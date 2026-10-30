<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Controller;

use AvalynxOffice\Entity\Address;
use AvalynxOffice\Entity\User;
use AvalynxOffice\Repository\AddressRepository;
use AvalynxOffice\Service\DataService;
use AvalynxOffice\Service\Mail\UserAccountProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DataController extends AbstractController
{
    #[Route(path: '/data/all', name: 'data_all')]
    public function all(DataService $dataService, UserAccountProvider $accountProvider): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $data = $dataService->getAllData($accountProvider->forUser($user));

        return new JsonResponse($data, Response::HTTP_OK, [], false);
    }

    #[Route(path: '/data/address-suggestions', name: 'data_address_suggestions')]
    public function addressSuggestions(Request $request, AddressRepository $addressRepository, UserAccountProvider $accountProvider): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $search = (string) $request->query->get('search', '');
        $suggestions = array_map(
            fn (Address $address): array => [
                'key' => $address->getEmail(),
                'value' => $this->formatAddressLabel($address),
            ],
            $addressRepository->findForAutocomplete($search, $accountProvider->forUser($user))
        );

        return new JsonResponse($suggestions);
    }

    private function formatAddressLabel(Address $address): string
    {
        return $address->getName()
            ? sprintf('%s <%s>', $address->getName(), $address->getEmail())
            : $address->getEmail();
    }
}
