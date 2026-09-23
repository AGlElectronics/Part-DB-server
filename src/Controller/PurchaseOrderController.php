<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Purchasing\PurchaseOrder;
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
    ) {
    }

    #[Route('', name: 'purchase_orders_list', methods: ['GET'])]
    public function list(): Response
    {
        $this->denyAccessUnlessGranted('@parts.read');

        $orders = $this->entityManager->getRepository(PurchaseOrder::class)->findBy([], ['createdAt' => 'DESC', 'id' => 'DESC']);

        return $this->render('purchasing/order_list.html.twig', [
            'orders' => $orders,
        ]);
    }

    #[Route('/{id}', name: 'purchase_order_show', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function show(Request $request, PurchaseOrder $order): Response
    {
        $this->denyAccessUnlessGranted('@parts.read');

        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted('@parts.edit');
            if (!$this->isCsrfTokenValid('purchase_order_'.$order->getId(), (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }

            /** @var array<string, mixed> $rows */
            $rows = $request->request->all('lines');
            foreach ($order->getLines() as $line) {
                $row = $rows[(string) $line->getId()] ?? null;
                if (!is_array($row)) {
                    continue;
                }
                $line->setTargetStock((int) ($row['target'] ?? 0));
                $line->setQuantity((int) ($row['quantity'] ?? 0));
            }
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

    private function csvResponse(string $csv, string $filename): Response
    {
        return new Response($csv, Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}
