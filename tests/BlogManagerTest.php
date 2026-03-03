<?php

namespace App\Tests\Service;

use App\Entity\Blog;
use App\Entity\User;
use App\Service\BlogManager;
use PHPUnit\Framework\TestCase;

class BlogManagerTest extends TestCase
{
    public function testValidBlog()
    {
        $blog = new Blog();
        $blog->setTitre('Titre valide');
        $blog->setContent('Contenu valide avec plus de vingt caractères.');

        $manager = new BlogManager();

        $this->assertTrue($manager->validate($blog));
    }

    public function testBlogWithoutTitle()
    {
        $this->expectException(\InvalidArgumentException::class);

        $blog = new Blog();
        $blog->setContent('Contenu valide avec plus de vingt caractères.');

        $manager = new BlogManager();
        $manager->validate($blog);
    }

    public function testBlogWithLongTitle()
    {
        $this->expectException(\InvalidArgumentException::class);

        $blog = new Blog();
        $blog->setTitre(str_repeat('a', 101));
        $blog->setContent('Contenu valide avec plus de vingt caractères.');

        $manager = new BlogManager();
        $manager->validate($blog);
    }

    public function testBlogWithShortContent()
    {
        $this->expectException(\InvalidArgumentException::class);

        $blog = new Blog();
        $blog->setTitre('Titre valide');
        $blog->setContent('Trop court');

        $manager = new BlogManager();
        $manager->validate($blog);
    }

    public function testPublisherCanEditBlog()
    {
        $blog = new Blog();
        $user = new User();

        $blog->setPublisher($user);

        $manager = new BlogManager();

        $this->assertTrue($manager->canEdit($blog, $user));
    }

    public function testAdminCanDeleteBlog()
    {
        $blog = new Blog();
        $publisher = new User();
        $admin = new User();
        $admin->setRoles(['ROLE_ADMIN']);

        $blog->setPublisher($publisher);

        $manager = new BlogManager();

        $this->assertTrue($manager->canDelete($blog, $admin));
    }
}