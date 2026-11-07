<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Form;

use AvalynxOffice\Entity\Account;
use AvalynxOffice\Form\DataTransformer\IdnToAsciiTransformer;
use AvalynxOffice\Service\Idn\IdnService;
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
    public function __construct(
        private readonly IdnService $idnService = new IdnService(),
    ) {
    }

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
            ->add('users', TextType::class, [
                'label' => 'account.users',
                'label_attr' => ['class' => 'form-label'],
                'required' => false,
                'mapped' => false,
                'data' => $options['users_data'],
                'help' => 'account.users_help',
                'attr' => [
                    'class' => 'd-none',
                ],
            ]);

        // Aliases are the connector-to-account routing and belong to the
        // create dialog. Folders are management data: a new account starts
        // with the required system folders; the folder editor lives in the
        // edit dialog only.
        $builder
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
            ]);

        if ($options['is_edit']) {
            $builder->add('folders', TextareaType::class, [
                'label' => 'account.folders',
                'required' => false,
                'mapped' => false,
                'data' => $options['folders_data'],
                'help' => 'account.folders_help',
                'attr' => [
                    'rows' => 6,
                    'spellcheck' => 'false',
                ],
            ]);
        }

        $builder->add('save', SubmitType::class, [
            'label' => 'common.save',
            'attr' => ['class' => 'btn btn-primary'],
        ]);

        // IDN domains are normalized to punycode so the stored account
        // address matches mail routing and the connector domain form.
        $builder->get('email')->addViewTransformer(new IdnToAsciiTransformer($this->idnService));
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Account::class,
            'aliases_data' => '',
            'folders_data' => '',
            'users_data' => '',
            'connector_options' => [],
            'is_edit' => false,
        ]);
    }
}
