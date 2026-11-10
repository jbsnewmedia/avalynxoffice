<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Form;

use AvalynxOffice\Service\Backup\BackupScheduleService;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\GreaterThanOrEqual;
use Symfony\Component\Validator\Constraints\LessThanOrEqual;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Constraints\Type as TypeConstraint;

/**
 * "Automatic backups" settings on the admin backup page: toggle, interval
 * in hours, attachments toggle and retention count. All fields are
 * unmapped — the controller reads the values and persists them through
 * the BackupScheduleService.
 *
 * @extends AbstractType<mixed>
 *
 * @param array{settings: array{enabled: bool, intervalHours: int, includeFiles: bool, retention: int}} $options
 */
class BackupScheduleType extends AbstractType
{
    /**
     * @param FormBuilderInterface<mixed>                                                                   $builder
     * @param array{settings: array{enabled: bool, intervalHours: int, includeFiles: bool, retention: int}} $options
     */
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('enabled', CheckboxType::class, [
                'label' => 'admin.backup.auto_enabled',
                'help' => 'admin.backup.auto_enabled_help',
                'mapped' => false,
                'required' => false,
                'data' => $options['settings']['enabled'],
                'label_attr' => ['class' => 'form-check-label'],
                'attr' => ['class' => 'form-check-input'],
            ])
            ->add('intervalHours', IntegerType::class, [
                'label' => 'admin.backup.auto_interval',
                'help' => 'admin.backup.auto_interval_help',
                'mapped' => false,
                'required' => true,
                'data' => $options['settings']['intervalHours'],
                'label_attr' => ['class' => 'form-label'],
                'attr' => ['class' => 'form-control', 'min' => BackupScheduleService::MIN_INTERVAL_HOURS, 'max' => BackupScheduleService::MAX_INTERVAL_HOURS],
                'constraints' => [
                    new NotBlank(),
                    new TypeConstraint('numeric'),
                    new Range(min: BackupScheduleService::MIN_INTERVAL_HOURS, max: BackupScheduleService::MAX_INTERVAL_HOURS),
                ],
                'empty_data' => '',
            ])
            ->add('includeFiles', CheckboxType::class, [
                'label' => 'admin.backup.auto_include_files',
                'help' => 'admin.backup.auto_include_files_help',
                'mapped' => false,
                'required' => false,
                'data' => $options['settings']['includeFiles'],
                'label_attr' => ['class' => 'form-check-label'],
                'attr' => ['class' => 'form-check-input'],
            ])
            ->add('retention', IntegerType::class, [
                'label' => 'admin.backup.auto_retention',
                'help' => 'admin.backup.auto_retention_help',
                'mapped' => false,
                'required' => true,
                'data' => $options['settings']['retention'],
                'label_attr' => ['class' => 'form-label'],
                'attr' => ['class' => 'form-control', 'min' => BackupScheduleService::MIN_RETENTION, 'max' => BackupScheduleService::MAX_RETENTION],
                'constraints' => [
                    new NotBlank(),
                    new TypeConstraint('numeric'),
                    new GreaterThanOrEqual(BackupScheduleService::MIN_RETENTION),
                    new LessThanOrEqual(BackupScheduleService::MAX_RETENTION),
                ],
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
