<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Form;

use AvalynxOffice\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Admin user form (create and edit). All fields are unmapped: the controller
 * applies them explicitly, because email uniqueness, password hashing,
 * activation seat accounting and role changes have dedicated rules.
 *
 * @extends AbstractType<User>
 */
class UserAdminType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'admin.users.form.email',
                'mapped' => false,
                'empty_data' => '',
                'attr' => ['class' => 'form-control', 'autocomplete' => 'off'],
                'constraints' => [
                    new NotBlank(message: 'admin.users.email_required'),
                    new Email(message: 'admin.users.email_invalid'),
                ],
            ])
            ->add('password', PasswordType::class, [
                'label' => $options['is_edit'] ? 'admin.users.form.password_new' : 'admin.users.form.password',
                'mapped' => false,
                'required' => false,
                'always_empty' => false,
                'empty_data' => '',
                'attr' => ['class' => 'form-control', 'autocomplete' => 'new-password'],
                'constraints' => $options['is_edit'] ? [] : [new NotBlank(message: 'admin.users.password_required')],
            ])
            ->add('active', CheckboxType::class, [
                'label' => 'admin.users.form.active',
                'mapped' => false,
                'required' => false,
                'attr' => ['class' => 'form-check-input'],
            ])
            ->add('admin', CheckboxType::class, [
                'label' => 'admin.users.form.admin',
                'mapped' => false,
                'required' => false,
                'attr' => ['class' => 'form-check-input'],
            ])
            ->add('save', SubmitType::class, [
                'label' => 'common.save',
                'attr' => ['class' => 'btn-primary'],
            ]);
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'is_edit' => false,
            'csrf_token_id' => 'admin_user',
        ]);

        $resolver->setAllowedTypes('is_edit', 'bool');
    }
}
