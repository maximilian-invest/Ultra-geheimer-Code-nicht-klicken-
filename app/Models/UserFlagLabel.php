<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserFlagLabel extends Model
{
    protected $fillable = ['user_id', 'color', 'label'];

    public const COLORS = ['red', 'orange', 'yellow', 'green', 'blue', 'purple'];

    public const DEFAULT_LABELS = [
        'red'    => 'Rot',
        'orange' => 'Orange',
        'yellow' => 'Gelb',
        'green'  => 'Gruen',
        'blue'   => 'Blau',
        'purple' => 'Lila',
    ];

    public static function isValidColor(?string $color): bool
    {
        return is_string($color) && in_array($color, self::COLORS, true);
    }

    public static function labelsForUser(int $userId): array
    {
        $rows = self::where('user_id', $userId)->get(['color', 'label'])->keyBy('color');
        $out = [];
        foreach (self::COLORS as $c) {
            $out[$c] = $rows->has($c) ? $rows[$c]->label : self::DEFAULT_LABELS[$c];
        }
        return $out;
    }
}
