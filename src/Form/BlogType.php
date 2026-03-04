<?php

namespace App\Form;

use App\Entity\Blog;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\File;

class BlogType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // ✅ Champ Titre
            ->add('titre', TextType::class, [
                'label' => 'Titre de l\'article',
                'required' => true,
                'attr' => [
                    'class' => 'form-control form-control-lg',
                    'placeholder' => 'Ex: 10 Proven Study Techniques That Actually Work'
                ]
            ])
            
            // ✅ Champ Catégorie
            ->add('category', ChoiceType::class, [
                'label' => 'Catégorie',
                'choices' => [
                    'Study Tips' => 'study-tips',
                    'Mathématiques' => 'mathematics',
                    'Sciences' => 'science',
                    'Informatique' => 'computer-science',
                ],
                'required' => false,
                'placeholder' => 'Choisir une catégorie',
                'attr' => ['class' => 'form-select form-select-lg']
            ])
            
            // ✅ Champ Contenu
            ->add('content', TextareaType::class, [
                'label' => 'Contenu',
                'required' => true,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 15,
                    'placeholder' => 'Discover evidence-based study methods that can dramatically improve your learning efficiency and retention rates...'
                ]
            ])
            
            // ✅ Champ Statut
            ->add('status', ChoiceType::class, [
                'label' => 'Statut de publication',
                'choices' => [
                    'Publié' => 'published',
                    'Brouillon' => 'draft',
                    'En attente' => 'pending',
                    'Rejeté' => 'rejected',
                ],
                'required' => true,
                'placeholder' => 'Choisir un statut',
                'attr' => ['class' => 'form-select form-select-lg']
            ])

            // ✅ Champ Images (optionnel)
            ->add('images', FileType::class, [
                'label' => 'Images (optionnel)',
                'multiple' => true,
                'mapped' => false,
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'accept' => 'image/*'
                ],
                'constraints' => [
                    new All([
                        new File([
                            'maxSize' => '5M',
                            'maxSizeMessage' => 'L\'image ne doit pas dépasser {{ limit }} {{ suffix }}.',
                            'mimeTypes' => [
                                'image/jpeg',
                                'image/png',
                                'image/gif',
                                'image/webp',
                            ],
                            'mimeTypesMessage' => 'Veuillez uploader une image valide (JPG, PNG, GIF, WEBP).',
                        ]),
                    ]),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Blog::class,
        ]);
    }
}