<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class AiContentGenerator
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $openAiApiKey = ''
    ) {}

    public function generate(string $title, ?string $category = null): string
    {
        $title = trim($title);
        if (empty($title)) {
            throw new \InvalidArgumentException('Le titre est obligatoire pour générer du contenu.');
        }

        $category = $category ?: 'general';

        // Si une clé OpenAI est configurée, on l'utilise
        if (!empty($this->openAiApiKey)) {
            try {
                return $this->generateWithOpenAi($title, $category);
            } catch (\Exception $e) {
                // Fallback silencieux vers les templates si l'API échoue
            }
        }

        // Fallback : génération par templates locaux
        return $this->generateFromTemplates($title, $category);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // OPENAI API
    // ─────────────────────────────────────────────────────────────────────────

    private function generateWithOpenAi(string $title, string $category): string
    {
        $categoryLabels = [
            'study-tips'       => 'conseils et méthodes d\'apprentissage',
            'mathematics'      => 'mathématiques',
            'science'          => 'sciences',
            'computer-science' => 'informatique',
            'general'          => 'général',
        ];

        $categoryLabel = $categoryLabels[$category] ?? 'général';

        $prompt = "Tu es un expert en {$categoryLabel} et tu rédiges des articles éducatifs de haute qualité en français pour une plateforme d'apprentissage en ligne appelée FAHIMNI.\n\n"
            . "Rédige un article complet et bien structuré sur le sujet : « {$title} »\n"
            . "Catégorie : {$categoryLabel}\n\n"
            . "Contraintes :\n"
            . "- Rédigé entièrement en français, style clair, pédagogique et engageant\n"
            . "- 3 sections avec titres en Markdown (## Titre de section)\n"
            . "- Une introduction en premier paragraphe (sans titre)\n"
            . "- Une conclusion finale (## Conclusion)\n"
            . "- Entre 400 et 600 mots au total\n"
            . "- Exemples concrets adaptés aux étudiants\n"
            . "- Ne pas inclure de titre H1 car il est affiché séparément\n\n"
            . "Génère uniquement le contenu de l'article, sans commentaires supplémentaires.";

        $response = $this->httpClient->request('POST', 'https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->openAiApiKey,
                'Content-Type'  => 'application/json',
            ],
            'json' => [
                'model'    => 'gpt-4o-mini',
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
                'max_tokens'  => 1000,
                'temperature' => 0.7,
            ],
            'timeout' => 30,
        ]);

        $data    = $response->toArray();
        $content = trim($data['choices'][0]['message']['content'] ?? '');

        if (empty($content)) {
            throw new \RuntimeException('Réponse vide de l\'API OpenAI.');
        }

        return $content;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // FALLBACK : génération par templates locaux
    // ─────────────────────────────────────────────────────────────────────────

    private function generateFromTemplates(string $title, string $category): string
    {
        $intro     = $this->generateIntro($title, $category);
        $sections  = $this->generateSections($title, $category);
        $conclusion = $this->generateConclusion($title);

        return $intro . "\n\n" . $sections . "\n\n" . $conclusion;
    }

    private function generateIntro(string $title, string $category): string
    {
        $intros = [
            'study-tips' => [
                "Dans cet article, nous allons explorer « {$title} » et découvrir des méthodes d'apprentissage éprouvées qui transformeront votre façon d'étudier. Que vous soyez étudiant débutant ou expérimenté, ces techniques vous aideront à maximiser votre potentiel académique.",
                "L'apprentissage efficace est un art qui se perfectionne avec les bonnes stratégies. À travers « {$title} », nous vous présentons des approches modernes et scientifiquement validées pour améliorer vos performances scolaires.",
            ],
            'mathematics' => [
                "Les mathématiques sont le langage universel de la science et de la technologie. Dans « {$title} », nous allons démystifier des concepts fondamentaux et vous montrer comment les appliquer dans des situations concrètes.",
                "Plongeons dans le monde fascinant des mathématiques avec « {$title} ». Cet article vous guidera à travers les principes essentiels tout en rendant l'apprentissage accessible et engageant.",
            ],
            'science' => [
                "La science nous permet de comprendre le monde qui nous entoure. Dans « {$title} », nous explorons des découvertes passionnantes et leurs implications pour notre vie quotidienne.",
                "Bienvenue dans cette exploration scientifique de « {$title} ». Nous allons analyser les dernières avancées et comprendre comment elles façonnent notre compréhension du monde.",
            ],
            'computer-science' => [
                "L'informatique transforme notre société à une vitesse vertigineuse. Dans « {$title} », nous explorons les concepts clés et les technologies qui définissent l'avenir du numérique.",
                "Le monde du code et de la technologie évolue sans cesse. À travers « {$title} », découvrez les fondamentaux et les tendances actuelles qui révolutionnent le domaine informatique.",
            ],
            'general' => [
                "Dans cet article intitulé « {$title} », nous abordons un sujet passionnant qui mérite une attention particulière. Explorons ensemble les différentes facettes de cette thématique enrichissante.",
                "Bienvenue dans cette exploration approfondie de « {$title} ». Cet article vous propose une analyse complète et des perspectives nouvelles sur ce sujet captivant.",
            ],
        ];

        $pool = $intros[$category] ?? $intros['general'];
        return $pool[array_rand($pool)];
    }

    private function generateSections(string $title, string $category): string
    {
        $sectionTemplates = [
            'study-tips' => [
                [
                    'heading' => 'Pourquoi cette méthode est-elle efficace ?',
                    'content' => "Les recherches en neurosciences cognitives montrent que l'apprentissage actif est bien plus efficace que la lecture passive. En appliquant les principes de « {$title} », vous activez plusieurs zones du cerveau simultanément, ce qui renforce la mémorisation à long terme.\n\nLa répétition espacée, combinée à l'auto-évaluation régulière, permet de consolider les connaissances de manière durable. Les étudiants qui adoptent ces stratégies voient une amélioration significative de leurs résultats en quelques semaines.",
                ],
                [
                    'heading' => 'Comment appliquer ces techniques au quotidien',
                    'content' => "Pour intégrer efficacement ces méthodes dans votre routine d'étude :\n\n1. Planifiez des sessions d'étude courtes mais régulières (25-45 minutes)\n2. Utilisez la technique Pomodoro pour maintenir votre concentration\n3. Créez des fiches de révision actives plutôt que de simples résumés\n4. Testez-vous régulièrement sur les concepts appris\n5. Enseignez ce que vous avez appris à quelqu'un d'autre\n\nCes habitudes, une fois ancrées, deviennent des réflexes qui boostent naturellement votre apprentissage.",
                ],
                [
                    'heading' => 'Les erreurs courantes à éviter',
                    'content' => "Beaucoup d'étudiants commettent l'erreur de relire leurs notes passivement en pensant apprendre. Or, la science montre clairement que cette approche est peu efficace. De même, les sessions de « bourrage de crâne » la veille d'un examen ne permettent pas une rétention durable.\n\nPréférez plutôt des sessions régulières et variées, en alternant les sujets et les méthodes d'étude. Votre cerveau a besoin de temps pour consolider les informations.",
                ],
            ],
            'mathematics' => [
                [
                    'heading' => 'Les fondamentaux à maîtriser',
                    'content' => "Avant d'aborder les aspects avancés de « {$title} », il est essentiel de maîtriser les bases. Les mathématiques sont une discipline cumulative : chaque concept s'appuie sur les précédents.\n\nAssurez-vous de bien comprendre les définitions, les théorèmes fondamentaux et leurs démonstrations. Un bon mathématicien ne se contente pas de mémoriser des formules, il comprend la logique qui les sous-tend.",
                ],
                [
                    'heading' => 'Applications pratiques',
                    'content' => "Les mathématiques ne sont pas qu'une discipline théorique. Les concepts abordés dans « {$title} » trouvent des applications concrètes dans de nombreux domaines :\n\n- Ingénierie et architecture : calculs de structures et optimisation\n- Finance : modélisation des risques et analyse statistique\n- Intelligence artificielle : algorithmes d'apprentissage automatique\n- Physique : modélisation des phénomènes naturels\n\nComprendre ces liens entre théorie et pratique rend l'apprentissage plus motivant et significatif.",
                ],
                [
                    'heading' => 'Exercices recommandés',
                    'content' => "La pratique régulière est la clé de la réussite en mathématiques. Voici une progression d'exercices recommandée :\n\n1. Exercices de base : reproduire les exemples du cours\n2. Exercices d'application : résoudre des problèmes similaires avec des données différentes\n3. Exercices de synthèse : combiner plusieurs concepts dans un même problème\n4. Problèmes ouverts : développer votre créativité mathématique\n\nN'hésitez pas à refaire les exercices qui vous posent difficulté. La persévérance est votre meilleure alliée.",
                ],
            ],
            'science' => [
                [
                    'heading' => 'Le contexte scientifique',
                    'content' => "Pour bien comprendre « {$title} », il faut d'abord situer ce sujet dans son contexte scientifique global. Les découvertes récentes dans ce domaine ont ouvert de nouvelles perspectives passionnantes.\n\nLa méthode scientifique, basée sur l'observation, l'hypothèse, l'expérimentation et la conclusion, reste le socle de toute avancée dans ce domaine. Chaque nouvelle découverte s'appuie sur des décennies de recherches antérieures.",
                ],
                [
                    'heading' => 'Les découvertes clés',
                    'content' => "Plusieurs avancées majeures ont marqué l'évolution de « {$title} » :\n\n- Les travaux fondateurs qui ont posé les bases de notre compréhension actuelle\n- Les percées technologiques qui ont permis de nouvelles observations\n- Les théories unificatrices qui relient différents phénomènes entre eux\n- Les applications innovantes qui transforment notre quotidien\n\nChacune de ces découvertes représente un jalon important dans la construction du savoir scientifique.",
                ],
                [
                    'heading' => 'Perspectives futures',
                    'content' => "Le domaine de « {$title} » est en pleine évolution. Les chercheurs explorent actuellement de nouvelles pistes prometteuses qui pourraient révolutionner notre compréhension.\n\nLes technologies émergentes comme l'intelligence artificielle et le calcul quantique ouvrent des possibilités inédites pour la recherche scientifique. Les prochaines décennies promettent des découvertes qui transformeront notre vision du monde.",
                ],
            ],
            'computer-science' => [
                [
                    'heading' => 'Les concepts fondamentaux',
                    'content' => "Pour maîtriser « {$title} », il est crucial de comprendre les principes de base qui sous-tendent cette technologie. L'informatique repose sur des concepts logiques et mathématiques qui, une fois compris, permettent d'aborder n'importe quel problème technique.\n\nLes algorithmes, les structures de données et les paradigmes de programmation constituent le socle sur lequel repose toute application moderne.",
                ],
                [
                    'heading' => 'Mise en pratique',
                    'content' => "La théorie sans pratique reste stérile en informatique. Voici comment appliquer les concepts de « {$title} » :\n\n1. Commencez par des projets simples pour assimiler les bases\n2. Augmentez progressivement la complexité de vos réalisations\n3. Analysez le code source de projets open source reconnus\n4. Participez à des communautés de développeurs pour échanger\n5. Créez votre propre projet personnel pour consolider vos acquis\n\nLa pratique régulière du code est le meilleur moyen de progresser rapidement.",
                ],
                [
                    'heading' => 'Tendances et évolutions',
                    'content' => "Le domaine de « {$title} » évolue rapidement. Les tendances actuelles incluent :\n\n- L'intelligence artificielle et le machine learning qui transforment les applications\n- Le cloud computing qui révolutionne l'infrastructure informatique\n- La cybersécurité qui devient un enjeu majeur pour toutes les organisations\n- Le développement durable appliqué au numérique (Green IT)\n\nRester informé de ces évolutions est essentiel pour tout professionnel de l'informatique.",
                ],
            ],
            'general' => [
                [
                    'heading' => 'Comprendre les enjeux',
                    'content' => "Le sujet de « {$title} » soulève des questions importantes qui méritent une réflexion approfondie. Dans un monde en constante évolution, il est essentiel de comprendre les enjeux liés à cette thématique.\n\nLes experts s'accordent à dire que ce domaine connaîtra des transformations significatives dans les années à venir, rendant sa compréhension d'autant plus importante.",
                ],
                [
                    'heading' => 'Analyse approfondie',
                    'content' => "En examinant de plus près « {$title} », nous constatons que plusieurs facteurs clés entrent en jeu :\n\n- Les fondamentaux théoriques qui structurent notre compréhension\n- Les aspects pratiques et leurs implications concrètes\n- Les défis actuels et les solutions proposées\n- Les opportunités futures qui se dessinent\n\nCette analyse multi-dimensionnelle nous permet d'avoir une vision complète et nuancée du sujet.",
                ],
                [
                    'heading' => 'Conseils pratiques',
                    'content' => "Pour approfondir vos connaissances sur « {$title} », voici quelques recommandations :\n\n1. Consultez des sources fiables et diversifiées\n2. Échangez avec des personnes partageant vos centres d'intérêt\n3. Mettez en pratique ce que vous apprenez\n4. Restez curieux et ouvert aux nouvelles perspectives\n5. Partagez vos découvertes avec votre communauté\n\nL'apprentissage est un voyage continu qui s'enrichit au fil des expériences.",
                ],
            ],
        ];

        $sections = $sectionTemplates[$category] ?? $sectionTemplates['general'];
        $output = '';

        foreach ($sections as $section) {
            $output .= "## " . $section['heading'] . "\n\n" . $section['content'] . "\n\n";
        }

        return rtrim($output);
    }

    private function generateConclusion(string $title): string
    {
        $conclusions = [
            "En résumé, « {$title} » est un sujet riche et passionnant qui offre de nombreuses perspectives d'apprentissage. Nous espérons que cet article vous a fourni des clés utiles pour approfondir vos connaissances. N'hésitez pas à partager vos réflexions et expériences dans les commentaires !",
            "Nous arrivons à la fin de cette exploration de « {$title} ». Les concepts et méthodes présentés dans cet article constituent une base solide pour poursuivre votre apprentissage. Continuez à explorer, à pratiquer et à partager vos découvertes avec la communauté FAHIMNI !",
            "« {$title} » est un domaine en constante évolution qui récompense la curiosité et la persévérance. Gardez votre motivation intacte, appliquez les conseils partagés ici, et vous verrez des progrès significatifs. Bonne continuation dans votre parcours d'apprentissage !",
        ];

        return "## Conclusion\n\n" . $conclusions[array_rand($conclusions)];
    }
}
