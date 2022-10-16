<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Seeder;

class IssueTestSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@localhost',
        ]);

        $posts = Post::factory()
            ->recycle($user)
            ->count(25)
            ->create();

        // Let's prevent 5 posts from having comments.
        $comments = Comment::factory()
            ->recycle([ $user, $posts->take(20) ])
            ->count(50)
            ->create();
    }
}
