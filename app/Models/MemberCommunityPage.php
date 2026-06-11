<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class MemberCommunityPage extends Model
{
    protected $fillable = ['product_id', 'title', 'icon', 'slug', 'banner', 'position', 'is_public_posting', 'is_default'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_public_posting' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (MemberCommunityPage $page): void {
            if (empty($page->slug)) {
                $page->slug = static::uniqueSlugForProduct($page->product_id, (string) $page->title);
            } else {
                $page->slug = static::uniqueSlugForProduct($page->product_id, (string) $page->slug, isSlugBase: true);
            }
        });
    }

    public static function uniqueSlugForProduct(string|int $productId, string $source, bool $isSlugBase = false): string
    {
        $base = $isSlugBase ? Str::slug($source) : Str::slug($source);
        if ($base === '') {
            $base = 'page-'.substr(uniqid('', true), -8);
        }

        $slug = $base;
        $suffix = 2;
        while (static::query()->where('product_id', $productId)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(MemberCommunityPost::class, 'member_community_page_id')->latest();
    }
}
