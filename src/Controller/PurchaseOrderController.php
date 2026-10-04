<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Parts\Category;
use App\Entity\Parts\Part;
use App\Entity\Parts\PartLot;
use App\Entity\Parts\Supplier;
use App\Entity\PriceInformations\Orderdetail;
use App\Entity\Purchasing\PurchaseOrder;
use App\Entity\Purchasing\PurchaseOrderLine;
use App\Repository\StructuralDBElementRepository;
use App\Services\Purchasing\OrderCheckIn;
use App\Services\Purchasing\OrderCheckInException;
use App\Services\Purchasing\OrderCheckInLine;
use App\Services\Purchasing\PurchaseOrderList;
use App\Services\Purchasing\StockRefillCalculator;
use App\Services\Purchasing\SupplierOrderCsv;
use App\Services\Purchasing\SupplierPartNumberResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/orders')]
final class PurchaseOrderController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SupplierPartNumberResolver $partNumbers,
        private readonly SupplierOrderCsv $csv,
        private readonly StockRefillCalculator $stockRefill,
        private readonly OrderCheckIn $orderCheckIn,
        private readonly PurchaseOrderList $orderList,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'purchase_orders_list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        $this->denyAccessUnlessGranted('@parts.read');
        $kind = $request->query->get('kind');
        $view = $request->query->get('view') === 'history' ? 'history' : 'current';
        $grouped = $this->orderList->group($this->orders(), is_string($kind) && in_array($kind, [PurchaseOrder::KIND_ELEC, PurchaseOrder::KIND_MECH], true) ? $kind : 'all');

        return $this->render('purchasing/order_list.html.twig', [
            'openOrders' => $grouped['open'],
            'partialOrders' => $grouped['partial'],
            'receivedOrders' => $grouped['received'],
            'kind' => is_string($kind) && in_array($kind, [PurchaseOrder::KIND_ELEC, PurchaseOrder::KIND_MECH], true) ? $kind : 'all',
            'view' => $view,
        ]);
    }

    #[Route('', name: 'purchase_order_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $this->denyAccessUnlessGranted('@parts.edit');
        $this->assertCsrf($request, 'purchase_order_create');

        $order = new PurchaseOrder();
        $order->setKind($this->kindFromRequest($request));
        if (!$this->applyName($order, $request)) {
            return $this->redirectToRoute('purchase_orders_list');
        }
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
            if ($this->intField($request, 'order') <= 0 && !$this->hasName($request)) {
                $this->addFlash('error', 'purchase_order.name_required');

                return $this->redirectToRoute('purchase_order_add_part', ['parts' => $request->request->get('parts')]);
            }

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
            if (!$this->applyName($order, $request)) {
                return $this->redirectToRoute('purchase_order_show', ['id' => $order->getId()]);
            }
            $this->applyOrderedAt($order, $request);
            $this->updateLines($request, $order);
            $this->addPostedPart($request, $order);
            $this->addPostedExternal($request, $order);
            $this->entityManager->flush();
            $this->addFlash('success', 'purchase_order.saved');

            return $this->redirectToRoute('purchase_order_show', ['id' => $order->getId()]);
        }

        $lines = [];
        foreach ($order->getLines() as $line) {
            $part = $line->getPart();
            $lines[] = [
                'line' => $line,
                'stock' => $part?->getAmountSum(),
                'minimum' => $part?->getMinAmount(),
                'digikey' => $this->partNumbers->digikeyLine($line),
                'mouser' => $this->partNumbers->mouserLine($line),
            ];
        }

        return $this->render('purchasing/order_show.html.twig', [
            'order' => $order,
            'lines' => $lines,
            'suppliers' => $this->selectable(Supplier::class),
            'categories' => $this->selectable(Category::class),
        ]);
    }

    #[Route('/{id}/lines/{lineId}/create-part', name: 'purchase_order_create_part', requirements: ['id' => '\d+', 'lineId' => '\d+'], methods: ['POST'])]
    public function createPart(Request $request, PurchaseOrder $order, int $lineId): Response
    {
        $this->denyAccessUnlessGranted('@parts.edit');
        $this->assertCsrf($request, 'purchase_order_create_part_'.$order->getId().'_'.$lineId);
        $line = $this->lineById($order, $lineId);
        if (!$line instanceof PurchaseOrderLine || $line->isInDatabase()) {
            $this->addFlash('error', 'purchase_order.create_part.missing');

            return $this->redirectToRoute('purchase_order_show', ['id' => $order->getId()]);
        }

        $postedName = $request->request->get('adopt_name');
        $name = is_scalar($postedName) ? trim((string) $postedName) : '';
        if ($name === '') {
            $name = $line->getLabel();
        }
        $category = $this->entityManager->find(Category::class, $this->intField($request, 'category'));
        if (!$category instanceof Category || $category->isNotSelectable()) {
            $this->addFlash('error', 'purchase_order.create_part.category');

            return $this->redirectToRoute('purchase_order_show', ['id' => $order->getId()]);
        }

        $part = new Part();
        $part->setName(mb_substr($name, 0, 255));
        $part->setCategory($category);
        if (!$this->isGranted('create', $part)) {
            $this->addFlash('error', 'purchase_order.create_part.denied');

            return $this->redirectToRoute('purchase_order_show', ['id' => $order->getId()]);
        }

        $supplier = $line->getSupplier();
        $number = trim((string) $line->getSupplierPartNumber());
        if ($supplier instanceof Supplier && $number !== '') {
            $detail = new Orderdetail();
            $detail->setSupplier($supplier);
            $detail->setSupplierpartnr($number);
            $part->addOrderdetail($detail);
        }
        $this->entityManager->persist($part);
        $line->setPart($part);
        $this->entityManager->flush();
        $this->addFlash('success', 'purchase_order.create_part.done');

        return $this->redirectToRoute('purchase_order_show', ['id' => $order->getId()]);
    }

    #[Route('/{id}/check-in', name: 'purchase_order_check_in', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function checkIn(Request $request, PurchaseOrder $order): Response
    {
        $this->denyAccessUnlessGranted('@parts.edit');
        if ($order->getLines()->isEmpty()) {
            $this->addFlash('warning', 'purchase_order.check_in.empty_order');

            return $this->redirectToRoute('purchase_order_show', ['id' => $order->getId()]);
        }

        $comment = $this->checkInComment($request, $order);
        if ($request->isMethod('POST')) {
            $this->assertCsrf($request, 'purchase_order_check_in_'.$order->getId());
            try {
                $posted = $this->postedCheckIn($request);
                if ($posted === []) {
                    throw new OrderCheckInException('purchase_order.check_in.none_selected');
                }
                $resolved = $this->orderCheckIn->resolve($order, $posted);
                if ($request->request->get('step') === 'confirm') {
                    $this->orderCheckIn->apply($order, $resolved, $comment);
                    $this->addFlash('success', 'purchase_order.check_in.confirmed');

                    return $this->redirectToRoute('purchase_order_show', ['id' => $order->getId()]);
                }

                return $this->render('purchasing/order_check_in_review.html.twig', [
                    'order' => $order,
                    'rows' => $resolved,
                    'comment' => $comment,
                    'destinations' => $this->destinations($resolved),
                ]);
            } catch (OrderCheckInException $exception) {
                $this->addFlash('error', $this->translator->trans($exception->translationKey, $exception->parameters));

                return $this->redirectToRoute('purchase_order_check_in', ['id' => $order->getId()]);
            }
        }

        return $this->render('purchasing/order_check_in.html.twig', [
            'order' => $order,
            'rows' => $this->checkInRows($order),
            'arrivals' => $this->arrivalsByLine($order),
            'comment' => $comment,
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

    #[Route('/{id}/supplier.csv', name: 'purchase_order_supplier', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function supplierFile(Request $request, PurchaseOrder $order): Response
    {
        $this->denyAccessUnlessGranted('@parts.read');
        $supplier = $this->entityManager->find(Supplier::class, $this->intValue($request->query->get('supplier')));
        if (!$supplier instanceof Supplier) {
            $this->addFlash('error', 'purchase_order.supplier_missing');

            return $this->redirectToRoute('purchase_order_show', ['id' => $order->getId()]);
        }

        return $this->csvResponse($this->csv->forSupplier($order, $supplier), 'supplier-'.$supplier->getID().'-order-'.$order->getId().'.csv');
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

    private function addPostedExternal(Request $request, PurchaseOrder $order): void
    {
        $value = $request->request->get('external_name');
        if (!is_scalar($value)) {
            return;
        }
        $name = trim((string) $value);
        if ($name === '') {
            return;
        }

        $line = new PurchaseOrderLine();
        $line->setExternalName($name);
        $line->setQuantity(max(1, $this->intField($request, 'external_quantity')));
        $number = $request->request->get('external_supplier_part_number');
        if (is_scalar($number)) {
            $line->setSupplierPartNumber((string) $number);
        }
        $supplier = $this->entityManager->find(Supplier::class, $this->intField($request, 'external_supplier'));
        if ($supplier instanceof Supplier) {
            $line->setSupplier($supplier);
        }
        $order->addLine($line);
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
        $order->setKind($this->kindFromRequest($request));
        $this->applyName($order, $request);
        $this->entityManager->persist($order);

        return $order;
    }

    private function applyOrderedAt(PurchaseOrder $order, Request $request): void
    {
        if ($request->request->get('mark_ordered') !== '1' || $order->getOrderedAt() instanceof \DateTimeImmutable) {
            return;
        }
        $order->setOrderedAt(new \DateTimeImmutable('today'));
    }

    /**
     * @return list<array{line: PurchaseOrderLine, lots: list<PartLot>, lotLabels: array<int, string>, locations: list<\App\Entity\Parts\StorageLocation>}>
     */
    private function checkInRows(PurchaseOrder $order): array
    {
        $rows = [];
        foreach ($order->getLines() as $line) {
            $part = $line->getPart();
            if (!$part instanceof Part) {
                $rows[] = [
                    'line' => $line,
                    'lots' => [],
                    'lotLabels' => [],
                    'locations' => [],
                ];
                continue;
            }
            $lots = $this->orderCheckIn->usableLots($part);
            $labels = [];
            foreach ($lots as $lot) {
                $id = $lot->getID();
                if ($id !== null) {
                    $labels[$id] = $this->orderCheckIn->describeLot($lot);
                }
            }
            $rows[] = [
                'line' => $line,
                'lots' => $lots,
                'lotLabels' => $labels,
                'locations' => $lots === [] ? $this->orderCheckIn->locationChoices($part) : [],
            ];
        }

        return $rows;
    }

    /**
     * @param list<OrderCheckInLine> $rows
     *
     * @return array<int, string>
     */
    private function destinations(array $rows): array
    {
        $labels = [];
        foreach ($rows as $row) {
            $lineId = $row->line->getId();
            if ($lineId === null) {
                continue;
            }
            if ($row->newLot) {
                $labels[$lineId] = $this->translator->trans('purchase_order.check_in.new_lot', [
                    '%location%' => $row->location?->getName() ?? '',
                ]);
                continue;
            }
            $labels[$lineId] = $this->orderCheckIn->describeLot($row->lot);
        }

        return $labels;
    }

    /**
     * @return array<int, array{quantity: int, lotId: int, locationId: int}>
     */
    private function postedCheckIn(Request $request): array
    {
        /** @var array<mixed, mixed> $checks */
        $checks = $request->request->all('check');
        /** @var array<mixed, mixed> $quantities */
        $quantities = $request->request->all('qty');
        /** @var array<mixed, mixed> $lots */
        $lots = $request->request->all('lot');
        /** @var array<mixed, mixed> $locations */
        $locations = $request->request->all('location');
        $rows = [];
        foreach (array_keys($checks) as $id) {
            $key = (string) $id;
            if (!ctype_digit($key)) {
                continue;
            }
            $lineId = (int) $key;
            $rows[$lineId] = [
                'quantity' => $this->postedInt($quantities[$key] ?? null),
                'lotId' => $this->postedInt($lots[$key] ?? null),
                'locationId' => $this->postedInt($locations[$key] ?? null),
            ];
        }

        return $rows;
    }

    private function postedInt(mixed $value): int
    {
        if (!is_scalar($value) || !preg_match('/^\d+$/', trim((string) $value))) {
            return 0;
        }

        return (int) $value;
    }

    private function checkInComment(Request $request, PurchaseOrder $order): string
    {
        $value = $request->request->get('comment');
        $comment = is_scalar($value) ? trim((string) $value) : '';
        if ($comment === '') {
            $comment = $this->translator->trans('purchase_order.check_in.default_comment', ['%name%' => $order->getName()]);
        }

        return mb_substr($comment, 0, 255);
    }

    /**
     * @return array<int, list<array{at: \DateTimeImmutable, quantity: int}>>
     */
    private function arrivalsByLine(PurchaseOrder $order): array
    {
        $events = [];
        foreach ($order->getReceipts() as $receipt) {
            foreach ($receipt->getLines() as $item) {
                $lineId = $item->getOrderLine()?->getId();
                if ($lineId === null) {
                    continue;
                }
                $events[$lineId][] = [
                    'at' => $receipt->getReceivedAt(),
                    'quantity' => $item->getQuantity(),
                ];
            }
        }

        return $events;
    }

    private function applyName(PurchaseOrder $order, Request $request): bool
    {
        if (!$this->hasName($request)) {
            $this->addFlash('error', 'purchase_order.name_required');

            return false;
        }
        $value = $request->request->get('name');
        $order->setName(mb_substr(trim((string) $value), 0, 255));

        return true;
    }

    private function hasName(Request $request): bool
    {
        $value = $request->request->get('name');

        return is_scalar($value) && trim((string) $value) !== '';
    }

    private function kindFromRequest(Request $request): string
    {
        return $request->request->get('kind') === PurchaseOrder::KIND_MECH ? PurchaseOrder::KIND_MECH : PurchaseOrder::KIND_ELEC;
    }

    private function lineById(PurchaseOrder $order, int $lineId): ?PurchaseOrderLine
    {
        foreach ($order->getLines() as $line) {
            if ($line->getId() === $lineId) {
                return $line;
            }
        }

        return null;
    }

    /**
     * @param class-string $class
     *
     * @return list<array{id: int, label: string}>
     */
    private function selectable(string $class): array
    {
        $repository = $this->entityManager->getRepository($class);
        if (!$repository instanceof StructuralDBElementRepository) {
            return [];
        }
        $options = [];
        foreach ($repository->getFlatList() as $element) {
            $id = $element->getID();
            if ($element->isNotSelectable() || $id === null) {
                continue;
            }
            $options[] = [
                'id' => $id,
                'label' => str_repeat('– ', $element->getLevel()).$element->getName(),
            ];
        }

        return $options;
    }

    private function intValue(mixed $value): int
    {
        if (!is_scalar($value) || !preg_match('/^\d+$/', trim((string) $value))) {
            return 0;
        }

        return (int) $value;
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
