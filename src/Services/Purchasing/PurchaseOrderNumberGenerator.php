<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Entity\Purchasing\PurchaseOrder;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Builds purchase order numbers such as Elec-20260001 and Mech-20260001.
 * The sequence starts at 0001 for each kind and year.
 */
final class PurchaseOrderNumberGenerator
{
    /** @var array<string, int> */
    private array $allocated = [];

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function next(string $kind, \DateTimeImmutable $on): string
    {
        $prefix = ($kind === PurchaseOrder::KIND_MECH ? 'Mech' : 'Elec').'-'.$on->format('Y');
        $max = $this->allocated[$prefix] ?? 0;

        /** @var list<string> $numbers */
        $numbers = $this->entityManager->createQueryBuilder()
            ->select('o.number')
            ->from(PurchaseOrder::class, 'o')
            ->where('o.number LIKE :prefix')
            ->setParameter('prefix', $prefix.'%')
            ->getQuery()
            ->getSingleColumnResult();

        foreach ($numbers as $number) {
            if (preg_match('/^'.preg_quote($prefix, '/').'(\d+)$/', $number, $matches) === 1) {
                $max = max($max, (int) $matches[1]);
            }
        }

        $next = $max + 1;
        $this->allocated[$prefix] = $next;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
