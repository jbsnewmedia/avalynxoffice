<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Controller;

use AvalynxOffice\Entity\RecoveryAddress;
use AvalynxOffice\Entity\User;
use AvalynxOffice\Repository\UserRepository;
use AvalynxOffice\Service\UserService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class ProfileController extends AbstractController
{
    public function __construct(
        private readonly UserService $userService,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly UriSigner $uriSigner,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    #[Route('/profile', name: 'app_profile')]
    public function index(Request $request): Response
    {
        if (!$request->isXmlHttpRequest()) {
            return $this->redirectToRoute('mail_index');
        }

        /** @var User $user */
        $user = $this->getUser();

        return $this->render('profile/_modal_content.html.twig', [
            'user' => $user,
        ]);
    }

    #[Route('/profile/recovery/add', name: 'app_profile_recovery_add', methods: ['POST'])]
    public function addRecoveryAddress(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $email = (string) $request->request->get('email', '');

        if ('' === $email) {
            return new JsonResponse(['success' => false, 'message' => 'The email address must not be empty.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->userService->addRecoveryAddress($user, $email);
            $this->entityManager->flush();
        } catch (\DomainException $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse(['success' => true, 'message' => 'Recovery address successfully added.']);
    }

    #[Route('/profile/recovery/remove/{id}', name: 'app_profile_recovery_remove', methods: ['POST'])]
    public function removeRecoveryAddress(string $id): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $recoveryAddress = $this->entityManager->find(RecoveryAddress::class, $id);

        if (!$recoveryAddress instanceof RecoveryAddress || $recoveryAddress->getUser()?->getId() !== $user->getId()) {
            return new JsonResponse(['success' => false, 'message' => 'Recovery address not found.'], Response::HTTP_NOT_FOUND);
        }

        $this->userService->removeRecoveryAddress($user, $recoveryAddress);
        $this->entityManager->flush();

        return new JsonResponse(['success' => true, 'message' => 'Recovery address successfully removed.']);
    }

    #[Route('/profile/password-reset/request', name: 'app_profile_password_reset_request', methods: ['POST'])]
    public function requestPasswordReset(Request $request): Response
    {
        $email = (string) $request->request->get('email', '');

        if ('' === $email) {
            return new JsonResponse(['success' => false, 'message' => 'The email address must not be empty.'], Response::HTTP_BAD_REQUEST);
        }

        $user = $this->userRepository->findOneBy(['email' => strtolower($email)]);

        if (null === $user || [] === $user->getRecoveryAddresses()->toArray()) {
            // To avoid user enumeration, we return a success message anyway.
            return new JsonResponse(['success' => true, 'message' => 'If an account exists and has recovery addresses, an email has been sent.']);
        }

        $this->userService->requestPasswordReset($email, $this->generateUrl('app_profile_password_reset_execute', ['userId' => $user->getId()], UrlGeneratorInterface::ABSOLUTE_URL));

        return new JsonResponse(['success' => true, 'message' => 'If an account exists and has recovery addresses, an email has been sent.']);
    }

    #[Route('/profile/password-reset/execute/{userId}', name: 'app_profile_password_reset_execute', methods: ['GET'])]
    public function executePasswordReset(Request $request, string $userId): Response
    {
        // Verify the signature of the URL.
        if (!$this->uriSigner->check($request->getUri())) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid or expired reset link.'], Response::HTTP_BAD_REQUEST);
        }

        $user = $this->userRepository->find($userId);

        if (null === $user) {
            return new JsonResponse(['success' => false, 'message' => 'User not found.'], Response::HTTP_NOT_FOUND);
        }

        return $this->render('profile/password_reset.html.twig', [
            'userId' => $userId,
        ]);
    }

    #[Route('/profile/password-reset/submit/{userId}', name: 'app_profile_password_reset_submit', methods: ['POST'])]
    public function submitPasswordReset(Request $request, string $userId): Response
    {
        // Verify the signature of the URL.
        if (!$this->uriSigner->check($request->getUri())) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid or expired reset link.'], Response::HTTP_BAD_REQUEST);
        }

        $user = $this->userRepository->find($userId);

        if (null === $user) {
            return new JsonResponse(['success' => false, 'message' => 'User not found.'], Response::HTTP_NOT_FOUND);
        }

        $newPassword = (string) $request->request->get('password', '');

        if ('' === $newPassword) {
            return new JsonResponse(['success' => false, 'message' => 'The new password must not be empty.'], Response::HTTP_BAD_REQUEST);
        }

        $hashedPassword = $this->passwordHasher->hashPassword($user, $newPassword);
        $user->setPassword($hashedPassword);

        $this->entityManager->flush();

        return new JsonResponse(['success' => true, 'message' => 'Password successfully updated.']);
    }
}
