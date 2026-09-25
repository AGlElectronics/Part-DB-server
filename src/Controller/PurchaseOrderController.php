<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Parts\Part;
use App\Entity\Purchasing\PurchaseOrder;
use App\Entity\Purchasing\PurchaseOrderLine;
use App\Services\Purchasing\StockRefillCalculator;
use App\Services\Purchasing\SupplierOrderCsv;
use App\Services\Purchasing\SupplierPartNumberResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/orders')]
final class PurchaseOrderController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SupplierPartNumberResolver $partNumbers,
        private readonly SupplierOrderCsv $csv,
        private readonly StockRefillCalculator $stockRefill,
    ) {
    }

    #[Route('', name: 'purchase_orders_list', methods: ['GET'])]
    public function list(): Response
    {
        $this->denyAccessUnlessGranted('@parts.read');

        return $this->render('purchasing/order_list.html.twig', [
            'orders' => $this->orders(),
        ]);
    }

    #[Route('', name: 'purchase_order_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $this->denyAccessUnlessGranted('@parts.edit');
        $this->assertCsrf($request, 'purchase_order_create');

        $order = new PurchaseOrder();
        $this->applyName($order, $request);
        $this->entityManager->persist($order);
        $this->entityManager->flush();
        $this->addFlash('success', 'purchase_order.flash.created');

        return $this->redirectToRoute('purchase_order_show', ['id' => $order->getId()]);
    }

    #[Route('/add', name: 'purchase_order_add_part', methods: ['GET', 'POST'])]
    public function addPart(Request $request): Response
    {
        $this->denyAccessUnlessGranted('@parts.edit');

        $parts = $this->partsFromRequest($request);
        if ($parts === []) {
            $this->addFlash('error', 'purchase_order.no_parts');

            return $this->redirectToRoute('purchase_orders_list');
        }

        if ($request->isMethod('POST')) {
            $this->assertCsrf($request, 'purchase_order_add_part');

            $order = $this->orderFromRequest($request);
            $added = 0;
            $skipped = 0;
            foreach ($parts as $part) {
                if ($this->appendPart($order, $part)) {
                    ++$added;
                } else {
                    ++$skipped;
                }
            }
            $this->entityManager->flush();

            if ($added > 0) {
                $this->addFlash('success', 'purchase_order.part_added');
            }
            if ($skipped > 0) {
                $this->addFlash('warning', 'purchase_order.part_already');
            }

            return $this->redirectToRoute('purchase_order_show', ['id' => $order->getId()]);
        }

        return $this->render('purchasing/add_part.html.twig', [
            'parts' => $parts,
            'partIds' => implode(',', array_map(static fn (Part $part): string => (string) $part->getID(), $parts)),
            'orders' => $this->orders(),
        ]);
    }

    #[Route('/{id}', name: 'purchase_order_show', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function show(Request $request, PurchaseOrder $order): Response
    {
        $this->denyAccessUnlessGranted('@parts.read');

        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted('@parts.edit');
            $this->assertCsrf($request, 'purchase_order_'.$order->getId());
            $this->applyName($order, $request);
            $this->updateLines($request, $order);
            $this->addPostedPart($request, $order);
            $this->entityManager->flush();
            $this->addFlash('success', 'purchase_order.saved');

            return $this->redirectToRoute('purchase_order_show', ['id' => $order->getId()]);
        }

        $lines = [];
        foreach ($order->getLines() as $line) {
            $part = $line->getPart();
            $lines[] = [
                'line' => $line,
                'stock' => $part->getAmountSum(),
                'minimum' => $part->getMinAmount(),
                'digikey' => $this->partNumbers->digikey($part),
                'mouser' => $this->partNumbers->mouser($part),
            ];
        }

        return $this->render('purchasing/order_show.html.twig', [
            'order' => $order,
            'lines' => $lines,
        ]);
    }

    #[Route('/{id}/delete', name: 'purchase_order_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, PurchaseOrder $order): Response
    {
        $this->denyAccessUnlessGranted('@parts.edit');
        $this->assertCsrf($request, 'purchase_order_delete_'.$order->getId());

        $this->entityManager->remove($order);
        $this->entityManager->flush();
        $this->addFlash('success', 'purchase_order.deleted');

        return $this->redirectToRoute('purchase_orders_list');
    }

    #[Route('/{id}/digikey.csv', name: 'purchase_order_digikey', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function digikey(PurchaseOrder $order): Response
    {
        $this->denyAccessUnlessGranted('@parts.read');

        return $this->csvResponse($this->csv->digikey($order), 'digikey-order-'.$order->getId().'.csv');
    }

    #[Route('/{id}/mouser.csv', name: 'purchase_order_mouser', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function mouser(PurchaseOrder $order): Response
    {
        $this->denyAccessUnlessGranted('@parts.read');

        return $this->csvResponse($this->csv->mouser($order), 'mouser-order-'.$order->getId().'.csv');
    }

    private function updateLines(Request $request, PurchaseOrder $order): void
    {
        /** @var array<string, mixed> $rows */
        $rows = $request->request->all('lines');
        /** @var array<string, mixed> $remove */
        $remove = $request->request->all('remove');
        $dropping = [];
        foreach ($order->getLines() as $line) {
            $id = (string) $line->getId();
            if (isset($remove[$id])) {
                $dropping[] = $line;
                continue;
            }
            $row = $rows[$id] ?? null;
            if (!is_array($row)) {
                continue;
            }
            $line->setTargetStock((int) ($row['target'] ?? 0));
            $line->setQuantity((int) ($row['quantity'] ?? 0));
        }
        foreach ($dropping as $line) {
            $order->removeLine($line);
            $this->entityManager->remove($line);
        }
    }

    private function addPostedPart(Request $request, PurchaseOrder $order): void
    {
        $partId = $this->intField($request, 'add_part');
        if ($partId <= 0) {
            return;
        }

        $part = $this->visiblePart($partId);
        if (!$part instanceof Part) {
            $this->addFlash('error', 'purchase_order.part_missing');

            return;
        }
        if (!$this->appendPart($order, $part)) {
            $this->addFlash('warning', 'purchase_order.part_already');
        }
    }

    private function orderFromRequest(Request $request): PurchaseOrder
    {
        $orderId = $this->intField($request, 'order');
        if ($orderId > 0) {
            $order = $this->entityManager->find(PurchaseOrder::class, $orderId);
            if (!$order instanceof PurchaseOrder) {
                throw $this->createNotFoundException();
            }

            return $order;
        }

        $order = new PurchaseOrder();
        $this->applyName($order, $request);
        $this->entityManager->persist($order);

        return $order;
    }

    private function applyName(PurchaseOrder $order, Request $request): void
    {
        $value = $request->request->get('name');
        if (!is_scalar($value)) {
            return;
        }
        $name = trim((string) $value);
        if ($name === '') {
            return;
        }
        $order->setName(mb_substr($name, 0, 255));
    }

    private function appendPart(PurchaseOrder $order, Part $part): bool
    {
        if ($order->findLineForPart($part) instanceof PurchaseOrderLine) {
            return false;
        }

        $line = new PurchaseOrderLine();
        $line->setPart($part);
        $line->setTargetStock($this->stockRefill->targetStock($part->getMinAmount()));
        $line->setQuantity($this->stockRefill->orderQuantity($part->getAmountSum(), $part->getMinAmount()));
        $order->addLine($line);

        return true;
    }

    /**
     * @return list<Part>
     */
    private function partsFromRequest(Request $request): array
    {
        $raw = $request->request->get('parts');
        if (!is_scalar($raw) || trim((string) $raw) === '') {
            $raw = $request->query->get('parts');
        }
        if (!is_scalar($raw)) {
            return [];
        }

        $parts = [];
        $seen = [];
        foreach (explode(',', (string) $raw) as $id) {
            $id = trim($id);
            if ($id === '' || !ctype_digit($id) || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $part = $this->visiblePart((int) $id);
            if ($part instanceof Part) {
                $parts[] = $part;
            }
        }

        return $parts;
    }

    private function visiblePart(int $id): ?Part
    {
        $part = $this->entityManager->find(Part::class, $id);
        if (!$part instanceof Part || !$this->isGranted('read', $part)) {
            return null;
        }

        return $part;
    }

    private function intField(Request $request, string $key): int
    {
        $value = $request->request->get($key);
        if (!is_scalar($value) || !preg_match('/^\d+$/', trim((string) $value))) {
            return 0;
        }

        return (int) $value;
    }

    private function assertCsrf(Request $request, string $tokenId): void
    {
        $token = $request->request->get('_token');
        if (!is_scalar($token) || !$this->isCsrfTokenValid($tokenId, (string) $token)) {
            throw $this->createAccessDeniedException();
        }
    }

    /**
     * @return array<int, PurchaseOrder>
     */
    private function orders(): array
    {
        return $this->entityManager->getRepository(PurchaseOrder::class)->findBy([], ['createdAt' => 'DESC', 'id' => 'DESC']);
    }

    private function csvResponse(string $csv, string $filename): Response
    {
        return new Response($csv, Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}
