<?php

namespace App\Service\Persister;

use App\Entity\Contract;
use App\Entity\Exchange;
use App\Service\Exchange\Bitmart\Dto\ContractDto;
use Doctrine\ORM\EntityManagerInterface;

class ContractPersister
{
    public function __construct(
        private EntityManagerInterface $em
    ) {}

    public function persistFromDto(ContractDto $dto, string $exchangeName): Contract
    {
        // Récupère ou crée l'Exchange
        $exchange = $this->em->getRepository(Exchange::class)->find($exchangeName);
        if (!$exchange) {
            $exchange = new Exchange();
            $exchange->setName($exchangeName);
            $this->em->persist($exchange);
        }

        // Récupère ou crée le contrat
        $contract = $this->em->getRepository(Contract::class)->find($dto->symbol);
        if (!$contract) {
            $contract = new Contract();
            $contract->setSymbol($dto->symbol);
            $this->em->persist($contract);
        }

        // Mise à jour des champs pertinents depuis le DTO
        $contract
            ->setExchange($exchange)
            ->setBaseCurrency($dto->baseCurrency)
            ->setQuoteCurrency($dto->quoteCurrency)
            ->setIndexName($dto->indexName)
            ->setContractSize($dto->contractSize)
            ->setPricePrecision($dto->pricePrecision)
            ->setVolPrecision($dto->volPrecision)
            ->setLastPrice($dto->lastPrice);

        return $contract;
    }

    public function flush(): void
    {
        $this->em->flush();
    }
}
