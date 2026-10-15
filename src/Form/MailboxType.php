<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Mailbox;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<Mailbox>
 */
class MailboxType extends AbstractType
{
    #[\Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'mailbox.name',
                'required' => true,
            ])
            ->add('email', EmailType::class, [
                'label' => 'mailbox.email',
                'required' => true,
            ])
            ->add('aliases', TextareaType::class, [
                'label' => 'mailbox.aliases',
                'required' => false,
                'mapped' => false,
                'data' => $options['aliases_data'],
                'help' => 'mailbox.aliases_help',
                'attr' => [
                    'rows' => 6,
                    'spellcheck' => 'false',
                ],
            ])
            ->add('folders', TextareaType::class, [
                'label' => 'mailbox.folders',
                'required' => false,
                'mapped' => false,
                'data' => $options['folders_data'],
                'help' => 'mailbox.folders_help',
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
            'data_class' => Mailbox::class,
            'aliases_data' => '',
            'folders_data' => '',
        ]);
    }
}
