<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * "Create backup" form on the admin backup page. All fields are unmapped —
 * the controller reads the values and validates the conditional password
 * rules (password needed when encryption is on).
 *
 * @extends AbstractType<mixed>
 */
class BackupCreateType extends AbstractType
{
    /**
     * @param FormBuilderInterface<mixed> $builder
     */
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('includeFiles', CheckboxType::class, [
                'label' => 'admin.backup.create_include_files',
                'help' => 'admin.backup.create_include_files_help',
                'mapped' => false,
                'required' => false,
                'data' => true,
                'label_attr' => ['class' => 'form-label'],
            ])
            ->add('encrypt', CheckboxType::class, [
                'label' => 'admin.backup.create_encrypt',
                'help' => 'admin.backup.create_encrypt_help',
                'mapped' => false,
                'required' => false,
                'label_attr' => ['class' => 'form-label'],
                'attr' => [
                    'data-bs-toggle' => 'collapse',
                    'data-bs-target' => '#backup-password-fields',
                    'role' => 'button',
                    'aria-expanded' => 'false',
                    'aria-controls' => 'backup-password-fields',
                ],
            ])
            ->add('password', PasswordType::class, [
                'label' => 'admin.backup.create_password',
                'help' => 'admin.backup.create_password_help',
                'mapped' => false,
                'required' => false,
                'label_attr' => ['class' => 'form-label'],
                'attr' => ['class' => 'form-control', 'placeholder' => 'admin.backup.create_password_placeholder'],
            ])
            ->add('passwordRepeat', PasswordType::class, [
                'label' => 'admin.backup.create_password_repeat',
                'mapped' => false,
                'required' => false,
                'label_attr' => ['class' => 'form-label'],
                'attr' => ['class' => 'form-control', 'placeholder' => 'admin.backup.create_password_repeat_placeholder'],
            ])
            ->add('create', SubmitType::class, [
                'label' => 'admin.backup.create_submit',
                'attr' => ['class' => 'btn btn-primary'],
            ])
        ;
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
        ]);
    }
}
