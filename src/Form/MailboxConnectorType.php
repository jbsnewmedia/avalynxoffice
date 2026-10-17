<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Form;

use AvalynxOffice\Entity\Mailbox;
use AvalynxOffice\Entity\MailboxConnector;
use AvalynxOffice\Repository\MailboxRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<MailboxConnector>
 */
class MailboxConnectorType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('mailbox', EntityType::class, [
                'class' => Mailbox::class,
                'choice_label' => static fn (Mailbox $mailbox): string => sprintf('%s (%s)', $mailbox->getName(), $mailbox->getEmail()),
                'query_builder' => static fn (MailboxRepository $repository) => $repository->createQueryBuilder('m')->orderBy('m.name', 'ASC'),
                'label' => 'connector.mailbox',
                'placeholder' => 'mailbox.no_mailbox',
            ])
            ->add('name', TextType::class, [
                'label' => 'mailbox.name',
                'required' => true,
            ])
            ->add('email', EmailType::class, [
                'label' => 'mailbox.email',
                'required' => true,
            ])
            ->add('isPrimary', CheckboxType::class, [
                'label' => 'connector.primary',
                'required' => false,
            ])
            ->add('pop3Host', TextType::class, [
                'label' => 'mailbox.pop3_host',
                'required' => false,
            ])
            ->add('pop3Port', IntegerType::class, [
                'label' => 'mailbox.pop3_port',
                'required' => false,
            ])
            ->add('pop3Username', TextType::class, [
                'label' => 'mailbox.pop3_username',
                'required' => false,
            ])
            ->add('pop3Password', PasswordType::class, [
                'label' => 'mailbox.pop3_password',
                'required' => false,
                'mapped' => false,
                'empty_data' => '',
            ])
            ->add('pop3Security', ChoiceType::class, [
                'label' => 'mailbox.pop3_security',
                'required' => false,
                'placeholder' => 'mailbox.security.placeholder',
                'choices' => [
                    'mailbox.security.ssl_tls' => 'ssl',
                    'mailbox.security.starttls' => 'tls',
                ],
            ])
            ->add('pop3DeleteAfterFetch', CheckboxType::class, [
                'label' => 'mailbox.pop3_delete_after_fetch',
                'required' => false,
            ])
            ->add('smtpHost', TextType::class, [
                'label' => 'mailbox.smtp_host',
                'required' => false,
            ])
            ->add('smtpPort', IntegerType::class, [
                'label' => 'mailbox.smtp_port',
                'required' => false,
            ])
            ->add('smtpUsername', TextType::class, [
                'label' => 'mailbox.smtp_username',
                'required' => false,
            ])
            ->add('smtpPassword', PasswordType::class, [
                'label' => 'mailbox.smtp_password',
                'required' => false,
                'mapped' => false,
                'empty_data' => '',
            ])
            ->add('smtpSecurity', ChoiceType::class, [
                'label' => 'mailbox.smtp_security',
                'required' => false,
                'placeholder' => 'mailbox.security.placeholder',
                'choices' => [
                    'mailbox.security.ssl_tls' => 'ssl',
                    'mailbox.security.starttls' => 'tls',
                ],
            ])
            ->add('save', SubmitType::class, [
                'label' => 'common.save',
                'attr' => ['class' => 'btn btn-primary'],
            ])
        ;
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => MailboxConnector::class,
        ]);
    }
}
