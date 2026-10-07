<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\BrandImage;
use App\Support\Branding;
use App\Support\GstStates;
use Illuminate\Validation\Rule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BrandingController extends Controller
{
    /**
     * Public: the login page shows the name and logo before anyone signs in.
     */
    public function show(): JsonResponse
    {
        return response()->json(['data' => Branding::all()]);
    }

    /**
     * Admin: rename the business and upload or remove the logo and favicon
     * (multipart POST, since PHP does not parse files on PUT).
     */
    public function update(Request $request): JsonResponse
    {
        foreach (['gstin', 'pan'] as $field) {
            if (is_string($request->input($field))) {
                $request->merge([$field => mb_strtoupper(preg_replace('/\s+/', '', $request->input($field)))]);
            }
        }

        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:100'],
            'tagline' => ['nullable', 'string', 'max:150'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'favicon' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:1024'],
            'remove_logo' => ['sometimes', 'boolean'],
            'remove_favicon' => ['sometimes', 'boolean'],
            // Legal details printed as the seller on bills and documents.
            'legal_name' => ['nullable', 'string', 'max:150'],
            'gstin' => ['nullable', 'string', 'regex:'.GstStates::GSTIN_PATTERN],
            'pan' => ['nullable', 'string', 'regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:150'],
            'website' => ['nullable', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'state_code' => ['nullable', Rule::in(GstStates::codes())],
            'pincode' => ['nullable', 'regex:/^[1-9][0-9]{5}$/'],
        ], [
            'gstin.regex' => 'Enter a valid 15-character GSTIN, e.g. 33ABCDE1234F1Z5.',
            'pan.regex' => 'Enter a valid PAN, e.g. ABCDE1234F.',
            'pincode.regex' => 'Enter a 6-digit PIN code.',
            'logo.max' => 'The logo must be 2 MB or smaller.',
            'favicon.max' => 'The favicon must be 1 MB or smaller.',
        ]);

        DB::transaction(function () use ($request, $data) {
            Setting::put('company_name', trim($data['company_name']));
            Setting::put('tagline', filled($data['tagline'] ?? null) ? trim($data['tagline']) : '');

            // Only fields sent are changed, so older clients can't blank them.
            foreach (Branding::DETAILS as $key) {
                if ($request->exists($key)) {
                    Setting::put($key, filled($data[$key] ?? null) ? trim($data[$key]) : null);
                }
            }

            if ($request->hasFile('logo')) {
                Setting::put('logo', BrandImage::fromUpload($request->file('logo'), 'logo', 512));
            } elseif ($request->boolean('remove_logo')) {
                Setting::put('logo', null);
            }

            if ($request->hasFile('favicon')) {
                Setting::put('favicon', BrandImage::fromUpload($request->file('favicon'), 'favicon', 128, square: true));
            } elseif ($request->boolean('remove_favicon')) {
                Setting::put('favicon', null);
            }
        });

        return response()->json(['data' => Branding::all(), 'message' => 'Company details saved.']);
    }
}
