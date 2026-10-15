<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Address;
use App\Entity\User;
use App\Repository\AddressRepository;
use App\Service\DataService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DataController extends AbstractController
{
    #[Route(path: '/data/all', name: 'data_all')]
    public function all(DataService $dataService): JsonResponse
    {
        $data = $dataService->getAllData();

        return new JsonResponse($data, Response::HTTP_OK, [], false);
    }

    #[Route(path: '/data/address-suggestions', name: 'data_address_suggestions')]
    public function addressSuggestions(Request $request, AddressRepository $addressRepository): JsonResponse
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
            $addressRepository->findForAutocomplete($search)
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
