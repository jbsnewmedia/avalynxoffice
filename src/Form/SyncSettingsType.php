<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\GreaterThanOrEqual;
use Symfony\Component\Validator\Constraints\LessThanOrEqual;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Type as TypeConstraint;

/**
 * Settings of the sync service (batch sizes and pauses), edited on the
 * admin system status page. Values are stored in the app_setting store.
 *
 * @extends AbstractType<mixed>
 *
 * @param array{settings: array{intervalSeconds: int|null, busyIntervalSeconds: int|null, mailsLimit: int|null, contactsLimit: int|null, calendarLimit: int|null}} $options
 */
class SyncSettingsType extends AbstractType
{
    /**
     * @param FormBuilderInterface<mixed>                                                                                                                              $builder
     * @param array{settings: array{intervalSeconds: int|null, busyIntervalSeconds: int|null, mailsLimit: int|null, contactsLimit: int|null, calendarLimit: int|null}} $options
     */
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $number = static fn (string $label, string $help, ?int $data): array => [
            'label' => $label,
            'mapped' => false,
            'data' => $data,
            'required' => true,
            'label_attr' => ['class' => 'form-label'],
            'attr' => ['class' => 'form-control', 'min' => 0, 'max' => 100000],
            'help' => $help,
            'constraints' => [
                new NotBlank(),
                new TypeConstraint('numeric'),
                new GreaterThanOrEqual(0),
                new LessThanOrEqual(100000),
            ],
        ];

        $builder
            ->add('intervalSeconds', IntegerType::class, [
                ...$number('admin.system.interval', 'admin.system.interval_help', $options['settings']['intervalSeconds'] ?? null),
                'empty_data' => '',
            ])
            ->add('busyIntervalSeconds', IntegerType::class, [
                ...$number('admin.system.busy_interval', 'admin.system.busy_interval_help', $options['settings']['busyIntervalSeconds'] ?? null),
                'empty_data' => '',
            ])
            ->add('mailsLimit', IntegerType::class, [
                ...$number('admin.system.mails_limit', 'admin.system.mails_limit_help', $options['settings']['mailsLimit'] ?? null),
                'empty_data' => '',
            ])
            ->add('contactsLimit', IntegerType::class, [
                ...$number('admin.system.contacts_limit', 'admin.system.contacts_limit_help', $options['settings']['contactsLimit'] ?? null),
                'empty_data' => '',
            ])
            ->add('calendarLimit', IntegerType::class, [
                ...$number('admin.system.calendar_limit', 'admin.system.calendar_limit_help', $options['settings']['calendarLimit'] ?? null),
                'empty_data' => '',
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
            'data_class' => null,
            'settings' => [],
        ]);

        $resolver->setAllowedTypes('settings', 'array');
    }
}
