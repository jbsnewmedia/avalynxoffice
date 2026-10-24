<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace JBSNewMedia\Pop3ConnectorBundle\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Event\PreSubmitEvent;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Settings form of the POP3 connector plugin. Works directly on the stored
 * config array (data_class = null): every field name is a config key.
 * Empty password fields keep the stored password.
 */
/**
 * @extends AbstractType<mixed>
 */
class Pop3ConnectorSettingsType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->addEventListener(FormEvents::PRE_SET_DATA, static function (FormEvent $event): void {
                $data = $event->getData();
                if (!is_array($data)) {
                    return;
                }
                // The fetch progress counter is technical bookkeeping and
                // must not be edited in the form, but has to survive it.
                $data += ['server_total' => 0];
                $event->setData($data);
            })
            ->add('server_total', HiddenType::class, [
                'required' => false,
            ])
            ->add('pop3Host', TextType::class, [
                'label' => 'connector.pop3_host',
                'required' => false,
            ])
            ->add('pop3Port', IntegerType::class, [
                'label' => 'connector.pop3_port',
                'required' => false,
            ])
            ->add('pop3Username', TextType::class, [
                'label' => 'connector.pop3_username',
                'required' => false,
            ])
            ->add('pop3Password', PasswordType::class, [
                'label' => 'connector.pop3_password',
                'required' => false,
            ])
            ->add('pop3Security', ChoiceType::class, [
                'label' => 'connector.pop3_security',
                'required' => false,
                'placeholder' => 'connector.security.placeholder',
                'choices' => [
                    'connector.security.ssl_tls' => 'ssl',
                    'connector.security.starttls' => 'tls',
                ],
            ])
            ->add('pop3DeleteAfterFetch', CheckboxType::class, [
                'label' => 'connector.pop3_delete_after_fetch',
                'required' => false,
            ])
            ->add('smtpHost', TextType::class, [
                'label' => 'connector.smtp_host',
                'required' => false,
            ])
            ->add('smtpPort', IntegerType::class, [
                'label' => 'connector.smtp_port',
                'required' => false,
            ])
            ->add('smtpUsername', TextType::class, [
                'label' => 'connector.smtp_username',
                'required' => false,
            ])
            ->add('smtpPassword', PasswordType::class, [
                'label' => 'connector.smtp_password',
                'required' => false,
            ])
            ->add('smtpSecurity', ChoiceType::class, [
                'label' => 'connector.smtp_security',
                'required' => false,
                'placeholder' => 'connector.security.placeholder',
                'choices' => [
                    'connector.security.ssl_tls' => 'ssl',
                    'connector.security.starttls' => 'tls',
                ],
            ])
            ->add('save', SubmitType::class, [
                'label' => 'common.save',
                'attr' => ['class' => 'btn btn-primary'],
            ])
        ;

        // Empty password fields keep the stored password.
        foreach (['pop3Password', 'smtpPassword'] as $passwordField) {
            $builder->get($passwordField)->addEventListener(FormEvents::PRE_SUBMIT, static function (PreSubmitEvent $event): void {
                $value = $event->getData();
                if (!is_string($value) || '' === trim($value)) {
                    $event->setData($event->getForm()->getData());
                }
            });
        }
    }

    #[\Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
        ]);
    }
}
