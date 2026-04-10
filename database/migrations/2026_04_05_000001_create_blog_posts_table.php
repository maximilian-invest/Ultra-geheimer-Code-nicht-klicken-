<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('blog_posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('seo_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->text('excerpt')->nullable();
            $table->longText('content');
            $table->string('featured_image')->nullable();
            $table->string('featured_image_alt')->nullable();
            $table->string('author')->default('SR Homes');
            $table->string('category')->nullable();
            $table->json('tags')->nullable();
            $table->json('internal_links')->nullable();
            $table->enum('status', ['draft', 'published', 'archived'])->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->integer('reading_time_min')->default(5);
            $table->integer('sort_order')->default(0);
            $table->unsignedBigInteger('author_id')->nullable();
            $table->timestamps();
            $table->index(['status', 'published_at']);
            $table->index('category');
        });
    }
    public function down(): void {
        Schema::dropIfExists('blog_posts');
    }
};
