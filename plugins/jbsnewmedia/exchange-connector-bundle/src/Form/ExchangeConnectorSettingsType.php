<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace JBSNewMedia\ExchangeConnectorBundle\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Event\PreSetDataEvent;
use Symfony\Component\Form\Event\PreSubmitEvent;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Settings form of the Exchange connector plugin. Works directly on the
 * stored config array (data_class = null): every field name is a config
 * key. Empty password fields keep the stored password.
 */

/**
 * @extends AbstractType<mixed>
 */
class ExchangeConnectorSettingsType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->addEventListener(FormEvents::PRE_SET_DATA, static function (PreSetDataEvent $event): void {
                $data = $event->getData();
                if (!is_array($data)) {
                    return;
                }
                // The fetch progress counters are technical bookkeeping and
                // must not be edited in the form, but have to survive it.
                $data += ['mails_total' => 0, 'contacts_total' => 0, 'calendar_total' => 0];
                $event->setData($data);
            })
            ->add('mails_total', HiddenType::class, [
                'required' => false,
            ])
            ->add('contacts_total', HiddenType::class, [
                'required' => false,
            ])
            ->add('calendar_total', HiddenType::class, [
                'required' => false,
            ])
            ->add('ewsHost', TextType::class, [
                'label' => 'connector.exchange_host',
                'required' => false,
                'help' => 'connector.exchange_host_help',
            ])
            ->add('ewsUsername', TextType::class, [
                'label' => 'connector.exchange_username',
                'required' => false,
                'help' => 'connector.exchange_username_help',
            ])
            ->add('ewsPassword', PasswordType::class, [
                'label' => 'connector.exchange_password',
                'required' => false,
            ])
            ->add('ewsVersion', ChoiceType::class, [
                'label' => 'connector.exchange_version',
                'required' => false,
                'placeholder' => false,
                'choices' => [
                    'connector.exchange_version.2013_sp1' => 'exchange2013_sp1',
                    'connector.exchange_version.2013' => 'exchange2013',
                ],
                'data' => 'exchange2013_sp1',
            ])
            ->add('ewsDeleteAfterFetch', CheckboxType::class, [
                'label' => 'connector.exchange_delete_after_fetch',
                'required' => false,
                'help' => 'connector.exchange_delete_after_fetch_help',
            ])
            ->add('save', SubmitType::class, [
                'label' => 'common.save',
                'attr' => ['class' => 'btn btn-primary'],
            ])
        ;

        // Empty password fields keep the stored password.
        $builder->get('ewsPassword')->addEventListener(FormEvents::PRE_SUBMIT, static function (PreSubmitEvent $event): void {
            $value = $event->getData();
            if (!is_string($value) || '' === trim($value)) {
                $event->setData($event->getForm()->getData());
            }
        });
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
        ]);
    }
}
