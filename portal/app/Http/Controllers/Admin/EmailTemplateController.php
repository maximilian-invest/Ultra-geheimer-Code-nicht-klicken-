<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EmailTemplateController extends Controller
{
    /**
     * List all email templates.
     */
    public function index(Request $request): JsonResponse
    {
        $templates = DB::table('email_templates')
            ->orderBy('category')
            ->orderBy('name')
            ->get(['id', 'name', 'subject', 'body', 'category', 'created_at', 'updated_at']);

        return response()->json(['templates' => $templates], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Save (insert or update) a template.
     */
    public function save(Request $request): JsonResponse
    {
        $input = $request->json()->all();
        $id    = intval($input['id'] ?? 0);
        $name  = trim($input['name'] ?? '');

        if ($name === '') {
            return response()->json(['error' => 'Name erforderlich'], 400);
        }

        $data = [
            'name'     => $name,
            'subject'  => trim($input['subject'] ?? ''),
            'body'     => $input['body'] ?? '',
            'category' => trim($input['category'] ?? 'allgemein'),
        ];

        if ($id > 0) {
            $data['updated_at'] = now();
            DB::table('email_templates')->where('id', $id)->update($data);
            return response()->json(['ok' => true, 'id' => $id]);
        } else {
            $newId = DB::table('email_templates')->insertGetId($data);
            return response()->json(['ok' => true, 'id' => $newId]);
        }
    }

    /**
     * Delete a template.
     */
    public function delete(Request $request): JsonResponse
    {
        $input = $request->json()->all();
        $id    = intval($input['id'] ?? 0);

        if (!$id) {
            return response()->json(['error' => 'id erforderlich'], 400);
        }

        DB::table('email_templates')->where('id', $id)->delete();
        return response()->json(['ok' => true]);
    }
}
