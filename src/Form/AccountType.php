<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Form;

use AvalynxOffice\Entity\Account;
use AvalynxOffice\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @extends AbstractType<Account>
 */
class AccountType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'account.name',
                'required' => true,
                'label_attr' => ['class' => 'form-label'],
                'attr' => ['class' => 'form-control'],
                'constraints' => [new NotBlank()],
            ])
            ->add('email', EmailType::class, [
                'label' => 'account.email',
                'required' => true,
                'label_attr' => ['class' => 'form-label'],
                'attr' => ['class' => 'form-control'],
                'constraints' => [new NotBlank(), new Email()],
            ])
            ->add('users', EntityType::class, [
                'label' => 'account.users',
                'label_attr' => ['class' => 'form-label'],
                'class' => User::class,
                'choice_label' => 'email',
                'multiple' => true,
                'mapped' => false,
                'data' => $options['users_data'],
                'required' => false,
                'help' => 'account.users_help',
                'attr' => [
                    'class' => 'form-select',
                    'size' => '4',
                ],
            ])
            ->add('aliases', TextareaType::class, [
                'label' => 'account.aliases',
                'required' => false,
                'mapped' => false,
                'data' => $options['aliases_data'],
                'help' => 'account.aliases_help',
                'attr' => [
                    'rows' => 6,
                    'spellcheck' => 'false',
                ],
            ])
            ->add('folders', TextareaType::class, [
                'label' => 'account.folders',
                'required' => false,
                'mapped' => false,
                'data' => $options['folders_data'],
                'help' => 'account.folders_help',
                'attr' => [
                    'rows' => 6,
                    'spellcheck' => 'false',
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
            'data_class' => Account::class,
            'aliases_data' => '',
            'folders_data' => '',
            'users_data' => [],
            'connector_options' => [],
        ]);
    }
}
