<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AnthropicService;
use App\Services\OpenAiImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BlogController extends Controller
{
    public function list(Request $request): JsonResponse
    {
        $posts = DB::table('blog_posts')
            ->orderByDesc('published_at')
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($post) {
                $post->tags = json_decode($post->tags, true) ?? [];
                $post->internal_links = json_decode($post->internal_links, true) ?? [];
                if ($post->featured_image) {
                    $post->featured_image_url = url('/storage/' . $post->featured_image);
                }
                return $post;
            });

        return response()->json(['success' => true, 'posts' => $posts]);
    }

    public function get(Request $request): JsonResponse
    {
        $id = $request->query('id');
        if (!$id) {
            return response()->json(['error' => 'Missing id'], 400);
        }

        $post = DB::table('blog_posts')->find($id);
        if (!$post) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $post->tags = json_decode($post->tags, true) ?? [];
        $post->internal_links = json_decode($post->internal_links, true) ?? [];
        if ($post->featured_image) {
            $post->featured_image_url = url('/storage/' . $post->featured_image);
        }

        return response()->json(['success' => true, 'post' => $post]);
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->json()->all();

        $title = $data['title'] ?? null;
        $content = $data['content'] ?? '';

        if (!$title) {
            return response()->json(['error' => 'Title is required'], 400);
        }

        // Auto-generate slug from title if not provided
        $slug = $data['slug'] ?? null;
        if (!$slug) {
            $slug = Str::slug($title);
        }

        // Ensure slug is unique (skip check if we're updating the same post)
        $id = $data['id'] ?? null;
        $slugExists = DB::table('blog_posts')
            ->where('slug', $slug)
            ->when($id, fn($q) => $q->where('id', '!=', $id))
            ->exists();

        if ($slugExists) {
            $slug = $slug . '-' . time();
        }

        // Auto-calculate reading time (200 words/min)
        $wordCount = str_word_count(strip_tags($content));
        $readingTime = max(1, (int) ceil($wordCount / 200));

        $payload = [
            'title'               => $title,
            'slug'                => $slug,
            'seo_title'           => $data['seo_title'] ?? null,
            'meta_description'    => $data['meta_description'] ?? null,
            'excerpt'             => $data['excerpt'] ?? null,
            'content'             => $content,
            'featured_image'      => $data['featured_image'] ?? null,
            'featured_image_alt'  => $data['featured_image_alt'] ?? null,
            'author'              => $data['author'] ?? 'SR Homes',
            'category'            => $data['category'] ?? null,
            'tags'                => isset($data['tags']) ? json_encode($data['tags']) : null,
            'internal_links'      => isset($data['internal_links']) ? json_encode($data['internal_links']) : null,
            'status'              => $data['status'] ?? 'draft',
            'published_at'        => $data['published_at'] ?? null,
            'reading_time_min'    => $data['reading_time_min'] ?? $readingTime,
            'sort_order'          => $data['sort_order'] ?? 0,
            'author_id'           => $data['author_id'] ?? null,
            'updated_at'          => now(),
        ];

        if ($id) {
            DB::table('blog_posts')->where('id', $id)->update($payload);
            $post = DB::table('blog_posts')->find($id);
        } else {
            $payload['created_at'] = now();
            $id = DB::table('blog_posts')->insertGetId($payload);
            $post = DB::table('blog_posts')->find($id);
        }

        $post->tags = json_decode($post->tags, true) ?? [];
        $post->internal_links = json_decode($post->internal_links, true) ?? [];

        return response()->json(['success' => true, 'post' => $post]);
    }

    public function delete(Request $request): JsonResponse
    {
        $data = $request->json()->all();
        $id = $data['id'] ?? null;

        if (!$id) {
            return response()->json(['error' => 'Missing id'], 400);
        }

        $post = DB::table('blog_posts')->find($id);
        if (!$post) {
            return response()->json(['error' => 'Not found'], 404);
        }

        // Delete associated image if it exists
        if ($post->featured_image && Storage::disk('public')->exists($post->featured_image)) {
            Storage::disk('public')->delete($post->featured_image);
        }

        DB::table('blog_posts')->where('id', $id)->delete();

        return response()->json(['success' => true]);
    }

    public function publish(Request $request): JsonResponse
    {
        $data = $request->json()->all();
        $id = $data['id'] ?? null;

        if (!$id) {
            return response()->json(['error' => 'Missing id'], 400);
        }

        DB::table('blog_posts')->where('id', $id)->update([
            'status'       => 'published',
            'published_at' => now(),
            'updated_at'   => now(),
        ]);

        $post = DB::table('blog_posts')->find($id);

        return response()->json(['success' => true, 'post' => $post]);
    }

    public function unpublish(Request $request): JsonResponse
    {
        $data = $request->json()->all();
        $id = $data['id'] ?? null;

        if (!$id) {
            return response()->json(['error' => 'Missing id'], 400);
        }

        DB::table('blog_posts')->where('id', $id)->update([
            'status'     => 'draft',
            'updated_at' => now(),
        ]);

        $post = DB::table('blog_posts')->find($id);

        return response()->json(['success' => true, 'post' => $post]);
    }

    public function generateArticle(Request $request): JsonResponse
    {
        $data = $request->json()->all();
        $topic    = $data['topic'] ?? null;
        $keywords = $data['keywords'] ?? [];

        if (!$topic) {
            return response()->json(['error' => 'Missing topic'], 400);
        }

        $keywordsStr = is_array($keywords) ? implode(', ', $keywords) : $keywords;

        $systemPrompt = <<<PROMPT
Du bist ein erfahrener SEO-Content-Autor für ein österreichisches Immobilienunternehmen in Salzburg. 
Deine Aufgabe ist es, hochwertige, SEO-optimierte Blogartikel auf Deutsch zu schreiben.

Schreibe Inhalte für SR Homes – ein Immobilienmaklerunternehmen in Salzburg, Österreich.
Das Unternehmen bietet Immobilienverkauf, Vermietung, Bewertung und Beratung an.

Regeln:
- Schreibe auf natürlichem, professionellem Österreichisch (nicht Schweizerdeutsch oder Bundesdeutsch)
- Optimiere für SEO: verwende H2/H3-Überschriften im HTML-Format, nutze Keywords natürlich
- Füge lokale Bezüge zu Salzburg und Österreich ein wo sinnvoll
- Der Artikel soll informativ, hilfreich und vertrauenswürdig wirken
- Content-Länge: 800–1200 Wörter
- Verwende HTML-Markup für den content (p, h2, h3, ul, li, strong, em Tags)

Antworte NUR mit einem validen JSON-Objekt (kein Markdown, keine Erklärungen) mit diesen Feldern:
{
  "title": "Artikel-Titel (60 Zeichen max)",
  "seo_title": "SEO-Titel mit Keyword (60 Zeichen max)",
  "meta_description": "Meta-Beschreibung (155 Zeichen max)",
  "slug": "url-freundlicher-slug",
  "excerpt": "Kurze Zusammenfassung (150-200 Zeichen)",
  "content": "Vollständiger HTML-Artikel",
  "category": "Eine der Kategorien: Marktberichte, Kaufen, Verkaufen, Mieten, Tipps, Recht, Finanzen, Lokales",
  "tags": ["tag1", "tag2", "tag3"],
  "featured_image_alt": "Alt-Text für Titelbild"
}
PROMPT;

        $userMessage = "Thema: {$topic}";
        if ($keywordsStr) {
            $userMessage .= "\nZiel-Keywords: {$keywordsStr}";
        }

        $service = app(AnthropicService::class);
        $result = $service->chatJson($systemPrompt, $userMessage, 4000);

        if (!$result) {
            return response()->json(['error' => 'Article generation failed'], 500);
        }

        return response()->json(['success' => true, 'article' => $result]);
    }

    public function generateImage(Request $request): JsonResponse
    {
        $data = $request->json()->all();
        $prompt  = $data['prompt'] ?? null;
        $postId  = $data['post_id'] ?? null;
        $size    = $data['size'] ?? '1792x1024';
        $quality = $data['quality'] ?? 'standard';

        if (!$prompt) {
            return response()->json(['error' => 'Missing prompt'], 400);
        }

        $service = app(OpenAiImageService::class);
        $b64 = $service->generate($prompt, $size, $quality);

        if (!$b64) {
            return response()->json(['error' => 'Image generation failed'], 500);
        }

        // Save image to storage/app/public/blog/
        Storage::disk('public')->makeDirectory('blog');
        $filename = 'blog/' . Str::uuid() . '.png';
        Storage::disk('public')->put($filename, base64_decode($b64));

        $imageUrl = url('/storage/' . $filename);

        // Update post if post_id provided
        if ($postId) {
            DB::table('blog_posts')->where('id', $postId)->update([
                'featured_image' => $filename,
                'updated_at'     => now(),
            ]);
        }

        return response()->json([
            'success'   => true,
            'path'      => $filename,
            'url'       => $imageUrl,
        ]);
    }

    public function uploadImage(Request $request): JsonResponse
    {
        if (!$request->hasFile('image')) {
            return response()->json(['error' => 'No image file provided'], 400);
        }

        $file = $request->file('image');

        if (!$file->isValid()) {
            return response()->json(['error' => 'Invalid file upload'], 400);
        }

        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        if (!in_array($file->getMimeType(), $allowedMimes)) {
            return response()->json(['error' => 'Invalid file type. Only JPEG, PNG, WebP, GIF allowed'], 400);
        }

        Storage::disk('public')->makeDirectory('blog');
        $filename = 'blog/' . Str::uuid() . '.' . $file->getClientOriginalExtension();
        $written = Storage::disk('public')->put($filename, file_get_contents($file->getRealPath()));

        if (!$written || !Storage::disk('public')->exists($filename)) {
            Log::error('Blog image upload: file write failed', ['filename' => $filename]);
            return response()->json(['error' => 'Datei konnte nicht gespeichert werden'], 500);
        }

        $postId = $request->input('post_id');
        if ($postId) {
            DB::table('blog_posts')->where('id', $postId)->update([
                'featured_image' => $filename,
                'updated_at'     => now(),
            ]);
        }

        return response()->json([
            'success' => true,
            'path'    => $filename,
            'url'     => url('/storage/' . $filename),
        ]);
    }
}
