<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use Carbon\Carbon;

class ProfileController extends Controller
{
    /**
     * Show authenticated user profile
     * GET /api/profile
     */
    public function show(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'message' => 'Unauthenticated'
                ], 401);
            }

            // Return user data tanpa password
            return response()->json([
                'success' => true,
                'data' => [
                    'user_id' => $user->user_id,
                    'user_name' => $user->user_name,
                    'user_email' => $user->user_email,
                    'user_phone' => $user->user_phone,
                    'role_id' => $user->role_id,
                    'user_sts' => $user->user_sts,
                    'user_created' => $user->user_created,
                    'user_updated' => $user->user_updated,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('[PROFILE SHOW ERROR]', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data profile'
            ], 500);
        }
    }

    /**
     * Update authenticated user profile
     * PUT /api/profile
     */
    public function update(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'message' => 'Unauthenticated'
                ], 401);
            }

            // Validasi input
            $validator = Validator::make($request->all(), [
                'user_name' => 'sometimes|string|max:64|unique:tm_user,user_name,' . $user->user_id . ',user_id',
                'user_email' => 'sometimes|email|max:64|unique:tm_user,user_email,' . $user->user_id . ',user_id',
                'user_phone' => 'sometimes|string|max:64|nullable',
                'current_password' => 'required_with:new_password',
                'new_password' => 'sometimes|string|min:6|confirmed',
            ], [
                'user_name.unique' => 'Username sudah digunakan',
                'user_email.unique' => 'Email sudah digunakan',
                'user_email.email' => 'Format email tidak valid',
                'new_password.min' => 'Password minimal 6 karakter',
                'new_password.confirmed' => 'Konfirmasi password tidak cocok',
                'current_password.required_with' => 'Password lama harus diisi jika ingin mengganti password',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validasi gagal',
                    'errors' => $validator->errors()
                ], 422);
            }

            $updateData = [];

            // Update username
            if ($request->has('user_name') && $request->user_name !== $user->user_name) {
                $updateData['user_name'] = $request->user_name;
            }

            // Update email
            if ($request->has('user_email') && $request->user_email !== $user->user_email) {
                $updateData['user_email'] = $request->user_email;
            }

            // Update phone
            if ($request->has('user_phone')) {
                $updateData['user_phone'] = $request->user_phone;
            }

            // Update password jika ada
            if ($request->has('new_password')) {
                // Verifikasi password lama
                if (!Hash::check($request->current_password, $user->user_pass)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Password lama tidak sesuai'
                    ], 422);
                }

                $updateData['user_pass'] = Hash::make($request->new_password);
                
                Log::info('[PASSWORD CHANGED]', ['user_id' => $user->user_id]);
            }

            // Jika tidak ada perubahan
            if (empty($updateData)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tidak ada data yang diubah'
                ], 400);
            }

            // Update timestamp
            $updateData['user_updated'] = Carbon::now();

            // Update user
            $user->update($updateData);

            Log::info('[PROFILE UPDATED]', [
                'user_id' => $user->user_id,
                'updated_fields' => array_keys($updateData)
            ]);

            // Refresh user data
            $user->refresh();

            return response()->json([
                'success' => true,
                'message' => 'Profile berhasil diperbarui',
                'data' => [
                    'user_id' => $user->user_id,
                    'user_name' => $user->user_name,
                    'user_email' => $user->user_email,
                    'user_phone' => $user->user_phone,
                    'role_id' => $user->role_id,
                    'user_sts' => $user->user_sts,
                    'user_created' => $user->user_created,
                    'user_updated' => $user->user_updated,
                ]
            ]);

        } catch (\Illuminate\Database\QueryException $e) {
            Log::error('[PROFILE UPDATE DB ERROR]', [
                'error' => $e->getMessage(),
                'code' => $e->getCode()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui profile. Silakan coba lagi.'
            ], 500);

        } catch (\Exception $e) {
            Log::error('[PROFILE UPDATE ERROR]', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat memperbarui profile'
            ], 500);
        }
    }

    /**
     * Change password only
     * POST /api/profile/change-password
     */
    public function changePassword(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'message' => 'Unauthenticated'
                ], 401);
            }

            // Validasi
            $validator = Validator::make($request->all(), [
                'current_password' => 'required|string',
                'new_password' => 'required|string|min:6|confirmed',
            ], [
                'current_password.required' => 'Password lama harus diisi',
                'new_password.required' => 'Password baru harus diisi',
                'new_password.min' => 'Password baru minimal 6 karakter',
                'new_password.confirmed' => 'Konfirmasi password tidak cocok',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validasi gagal',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Verifikasi password lama
            if (!Hash::check($request->current_password, $user->user_pass)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Password lama tidak sesuai'
                ], 422);
            }

            // Cek password baru tidak sama dengan password lama
            if (Hash::check($request->new_password, $user->user_pass)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Password baru tidak boleh sama dengan password lama'
                ], 422);
            }

            // Update password
            $user->update([
                'user_pass' => Hash::make($request->new_password),
                'user_updated' => Carbon::now()
            ]);

            // Hapus semua token (logout dari semua device)
            $user->tokens()->delete();

            Log::info('[PASSWORD CHANGED]', ['user_id' => $user->user_id]);

            return response()->json([
                'success' => true,
                'message' => 'Password berhasil diubah. Silakan login kembali.'
            ]);

        } catch (\Exception $e) {
            Log::error('[CHANGE PASSWORD ERROR]', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengubah password'
            ], 500);
        }
    }

    /**
     * Update profile picture/avatar (jika ada)
     * POST /api/profile/avatar
     */
    public function updateAvatar(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'message' => 'Unauthenticated'
                ], 401);
            }

            $validator = Validator::make($request->all(), [
                'avatar' => 'required|image|mimes:jpeg,png,jpg|max:2048', // Max 2MB
            ], [
                'avatar.required' => 'File foto harus diupload',
                'avatar.image' => 'File harus berupa gambar',
                'avatar.mimes' => 'Format gambar harus jpeg, png, atau jpg',
                'avatar.max' => 'Ukuran gambar maksimal 2MB',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validasi gagal',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Upload logic here (sesuaikan dengan kebutuhan)
            // Contoh: simpan ke storage/app/public/avatars
            
            $file = $request->file('avatar');
            $filename = $user->user_id . '_' . time() . '.' . $file->getClientOriginalExtension();
            $path = $file->storeAs('avatars', $filename, 'public');

            // Update user avatar path (jika ada kolom untuk avatar di database)
            // $user->update(['user_avatar' => $path]);

            Log::info('[AVATAR UPDATED]', ['user_id' => $user->user_id, 'path' => $path]);

            return response()->json([
                'success' => true,
                'message' => 'Foto profil berhasil diupload',
                'data' => [
                    'avatar_url' => asset('storage/' . $path)
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('[UPDATE AVATAR ERROR]', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengupload foto profil'
            ], 500);
        }
    }

    /**
     * Delete user account
     * DELETE /api/profile
     */
    public function destroy(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'message' => 'Unauthenticated'
                ], 401);
            }

            // Validasi password untuk konfirmasi
            $validator = Validator::make($request->all(), [
                'password' => 'required|string',
            ], [
                'password.required' => 'Password harus diisi untuk konfirmasi',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validasi gagal',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Verifikasi password
            if (!Hash::check($request->password, $user->user_pass)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Password tidak sesuai'
                ], 422);
            }

            // Soft delete dengan update status
            $user->update([
                'user_sts' => 0, // Status tidak aktif
                'user_updated' => Carbon::now()
            ]);

            // Hapus semua token
            $user->tokens()->delete();

            Log::info('[ACCOUNT DELETED]', ['user_id' => $user->user_id]);

            return response()->json([
                'success' => true,
                'message' => 'Akun berhasil dihapus'
            ]);

        } catch (\Exception $e) {
            Log::error('[DELETE ACCOUNT ERROR]', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus akun'
            ], 500);
        }
    }
}