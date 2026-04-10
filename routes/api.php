<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\AdminApiController;
use App\Http\Controllers\Portal\PortalApiController;

// Health check
Route::get('/ping', function () {
    return response()->json(['status' => 'ok', 'timestamp' => now()->toIso8601String()]);
});

// Legacy admin API — maps ?action=xxx to sub-controllers
// Session-Middleware erlaubt Auth::user() aus Browser-Requests (Cookie).
// api.key bleibt fuer externe API-Aufrufe (Email Manager, MCP Tools).
Route::match(['get', 'post'], 'admin_api.php', [AdminApiController::class, 'handle'])
    ->middleware([
        'api.key',
        \Illuminate\Cookie\Middleware\EncryptCookies::class,
        \Illuminate\Session\Middleware\StartSession::class,
    ]);

// Legacy portal API endpoint
Route::match(["get", "post"], "portal_api.php", [PortalApiController::class, "handle"])
    ->middleware("api.key");


// Website Public API (no auth required, rate-limited)
Route::prefix('website')->middleware('throttle:60,1')->group(function () {
    Route::get('/properties', [\App\Http\Controllers\WebsiteApiController::class, 'properties']);
    Route::get('/property/{id}', [\App\Http\Controllers\WebsiteApiController::class, 'property']);
    Route::get('/image/{id}', [\App\Http\Controllers\WebsiteApiController::class, 'image']);
    Route::get('/content', [\App\Http\Controllers\WebsiteApiController::class, 'content']);
    Route::post('/upload', [\App\Http\Controllers\WebsiteApiController::class, 'upload']);
});
Route::get('/openimmo/willhaben.xml', [\App\Http\Controllers\OpenImmoController::class, 'willhabenFeed']);
Route::get('/openimmo/status', [\App\Http\Controllers\OpenImmoController::class, 'feedStatus']);

// Blog public API
Route::prefix('website/blog')->group(function () {
    Route::get('/posts', function (\Illuminate\Http\Request $request) {
        $posts = \DB::table('blog_posts')
            ->where('status', 'published')
            ->whereNotNull('published_at')
            ->select('id', 'title', 'slug', 'seo_title', 'meta_description', 'excerpt',
                     'featured_image', 'featured_image_alt', 'author', 'author_id', 'category',
                     'tags', 'published_at', 'reading_time_min')
            ->orderByDesc('published_at')
            ->get()
            ->map(function ($p) {
                $p->tags = json_decode($p->tags, true) ?? [];
                if ($p->featured_image) {
                    $p->featured_image_url = url('/storage/' . $p->featured_image);
                }
                if ($p->author) {
                    $au = \DB::table('users')->where('name', $p->author)->select('profile_image', 'signature_title')->first();
                    if ($au && $au->profile_image) $p->author_image = url('/storage/' . $au->profile_image);
                    if ($au) $p->author_title = $au->signature_title;
                }
                return $p;
            });
        return response()->json(['success' => true, 'posts' => $posts]);
    });

Route::get('/post/{slug}', function (string $slug) {
        $post = \DB::table('blog_posts')
            ->where('slug', $slug)
            ->where('status', 'published')
            ->first();
        if (!$post) return response()->json(['error' => 'Not found'], 404);
        $post->tags = json_decode($post->tags, true) ?? [];
        $post->internal_links = json_decode($post->internal_links, true) ?? [];
        if ($post->featured_image) {
            $post->featured_image_url = url('/storage/' . $post->featured_image);
        }

        // Generate TOC from headings and add anchor IDs
        $toc = [];
        $content = $post->content;

        // Handle HTML headings (h2, h3, h4)
        $content = preg_replace_callback(
            '/<(h[234])([^>]*)>(.*?)<\/\1>/si',
            function ($m) use (&$toc) {
                $tag = $m[1];
                $attrs = $m[2];
                $text = strip_tags($m[3]);
                $level = (int) substr($tag, 1);
                $anchor = \Illuminate\Support\Str::slug($text);
                // Ensure unique anchors
                static $used = [];
                $base = $anchor;
                $i = 1;
                while (isset($used[$anchor])) {
                    $anchor = $base . '-' . $i++;
                }
                $used[$anchor] = true;
                $toc[] = ['level' => $level, 'text' => $text, 'anchor' => $anchor];
                // Add id attribute if not already present
                if (stripos($attrs, 'id=') === false) {
                    return "<{$tag}{$attrs} id=\"{$anchor}\">{$m[3]}</{$tag}>";
                }
                return $m[0];
            },
            $content
        );

        // Handle markdown headings (##, ###, ####)
        $content = preg_replace_callback(
            '/^(#{2,4})\s+(.+)$/m',
            function ($m) use (&$toc) {
                $level = strlen($m[1]);
                $text = trim($m[2]);
                $anchor = \Illuminate\Support\Str::slug($text);
                static $used_md = [];
                $base = $anchor;
                $i = 1;
                while (isset($used_md[$anchor])) {
                    $anchor = $base . '-' . $i++;
                }
                $used_md[$anchor] = true;
                $toc[] = ['level' => $level, 'text' => $text, 'anchor' => $anchor];
                $tag = 'h' . $level;
                return "<{$tag} id=\"{$anchor}\">{$text}</{$tag}>";
            },
            $content
        );

        $post->toc = $toc;

        $post->content = $content;

        // Author data
        if ($post->author_id) {
            $author = \DB::table('users')->where('id', $post->author_id)
                ->select('name', 'signature_title', 'profile_image')->first();
            if ($author) {
                $post->author_name = $author->name;
                $post->author_title = $author->signature_title;
                $post->author_image = $author->profile_image ? url('/storage/' . $author->profile_image) : null;
            }
        } elseif ($post->author) {
            $author = \DB::table('users')->where('name', $post->author)
                ->select('name', 'signature_title', 'profile_image')->first();
            if ($author) {
                $post->author_name = $author->name;
                $post->author_title = $author->signature_title;
                $post->author_image = $author->profile_image ? url('/storage/' . $author->profile_image) : null;
            }
        }
        $related = \DB::table('blog_posts')
            ->where('status', 'published')
            ->where('id', '!=', $post->id)
            ->when($post->category, fn($q) => $q->where('category', $post->category))
            ->select('id', 'title', 'slug', 'excerpt', 'featured_image', 'published_at', 'reading_time_min')
            ->orderByDesc('published_at')
            ->limit(3)
            ->get()
            ->map(function ($p) {
                if ($p->featured_image) $p->featured_image_url = url('/storage/' . $p->featured_image);
                return $p;
            });
        return response()->json(['success' => true, 'post' => $post, 'related' => $related]);
    });


    Route::get('/sitemap.xml', function () {
        $posts = \DB::table('blog_posts')->where('status', 'published')->select('slug', 'updated_at')->get();
        $xml = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach (['', 'immobilien', 'verkaufen', 'bewerten', 'kontakt', 'ueber-uns', 'portal', 'blog'] as $p) {
            $xml .= "<url><loc>https://www.sr-homes.at/{$p}</loc><changefreq>weekly</changefreq><priority>" . ($p === '' ? '1.0' : '0.8') . "</priority></url>";
        }
        foreach ($posts as $post) {
            $lastmod = $post->updated_at ? date('Y-m-d', strtotime($post->updated_at)) : date('Y-m-d');
            $xml .= "<url><loc>https://www.sr-homes.at/{$post->slug}</loc><lastmod>{$lastmod}</lastmod><changefreq>monthly</changefreq><priority>0.7</priority></url>";
        }
        $xml .= '</urlset>';
        return response($xml, 200, ['Content-Type' => 'application/xml']);
    });
});
