import * as FileSystem from 'expo-file-system/legacy';
import type { ImagePickerAsset } from 'expo-image-picker';
import { Platform } from 'react-native';
import { api } from '@/src/api/client';
import type { ChildProfilePhoto, MobileUpload } from '@/src/api/types';
import{track}from'@/src/analytics';

type Progress = (value: number) => void;

export async function uploadChildPhoto(childId: string, asset: ImagePickerAsset, onProgress: Progress, existingUploadId?: string, onUploadId?: (id: string) => void): Promise<ChildProfilePhoto> {
  const webFile = Platform.OS === 'web' ? asset.file : undefined;
  const info = webFile ? null : await FileSystem.getInfoAsync(asset.uri);
  const size = asset.fileSize ?? webFile?.size ?? (info?.exists && 'size' in info ? info.size : undefined);
  if (!size) throw new Error('Unable to read the selected image size.');
  void track('photo_upload_started',{child_profile_id:childId,file_size:size});
  const mimeType = normalizeMime(asset.mimeType, asset.fileName);
  let upload = existingUploadId
    ? (await api<{ data: MobileUpload }>(`/uploads/${existingUploadId}`)).data
    : (await api<{ data: MobileUpload }>('/uploads', { method: 'POST', body: JSON.stringify({ purpose: 'child_reference', child_profile_id: childId, filename: asset.fileName ?? `child-${Date.now()}.jpg`, mime_type: mimeType, size }) })).data;
  onUploadId?.(upload.id);

  const count = Math.ceil(upload.expected_size / upload.chunk_size);
  for (let index = 0; index < count; index += 1) {
    if (upload.received_chunks.includes(index)) continue;
    const position = index * upload.chunk_size;
    const length = Math.min(upload.chunk_size, upload.expected_size - position);
    const form = new FormData();
    if (webFile) {
      form.append('chunk', webFile.slice(position, position + length), `chunk-${index}.part`);
      upload = (await api<{ data: MobileUpload }>(`/uploads/${upload.id}/chunks/${index}`, { method: 'POST', body: form })).data;
      onProgress(upload.progress);
      continue;
    }

    const base64 = await FileSystem.readAsStringAsync(asset.uri, { encoding: FileSystem.EncodingType.Base64, position, length });
    const temporaryUri = `${FileSystem.cacheDirectory}herokid-${upload.id}-${index}.part`;
    await FileSystem.writeAsStringAsync(temporaryUri, base64, { encoding: FileSystem.EncodingType.Base64 });
    try {
      form.append('chunk', { uri: temporaryUri, name: `chunk-${index}.part`, type: 'application/octet-stream' } as unknown as Blob);
      upload = (await api<{ data: MobileUpload }>(`/uploads/${upload.id}/chunks/${index}`, { method: 'POST', body: form })).data;
      onProgress(upload.progress);
    } finally {
      await FileSystem.deleteAsync(temporaryUri, { idempotent: true });
    }
  }

  if (upload.status !== 'completed') throw new Error(upload.error ?? 'The upload did not complete.');
  const photo=(await api<{ data: ChildProfilePhoto }>(`/uploads/${upload.id}/attach-child-photo`, { method: 'POST', body: JSON.stringify({ child_profile_id: childId, reuse_consent: true }) })).data;
  void track('photo_upload_completed',{child_profile_id:childId,upload_id:upload.id});
  return photo;
}

function normalizeMime(mime: string | null | undefined, filename: string | null | undefined): string {
  if (mime && mime !== 'image/jpg') return mime.toLowerCase();
  const name = (filename ?? '').toLowerCase();
  if (name.endsWith('.png')) return 'image/png';
  if (name.endsWith('.webp')) return 'image/webp';
  if (name.endsWith('.heic')) return 'image/heic';
  if (name.endsWith('.heif')) return 'image/heif';
  return 'image/jpeg';
}
