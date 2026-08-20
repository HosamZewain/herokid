import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { router, useLocalSearchParams } from 'expo-router';
import { Alert, Pressable, StyleSheet, Text, View } from 'react-native';
import { api } from '@/src/api/client';
import type { ChildProfile, ChildProfilePhoto } from '@/src/api/types';
import { AuthenticatedImage } from '@/src/components/AuthenticatedImage';
import { Button } from '@/src/components/Button';
import { Screen } from '@/src/components/Screen';
import { useI18n } from '@/src/i18n';
import { colors, radii } from '@/src/theme';

export default function ChildDetailScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { l, isRtl } = useI18n();
  const client = useQueryClient();
  const child = useQuery({ queryKey: ['child', id], queryFn: () => api<{ data: ChildProfile }>(`/children/${id}`) });
  const photos = useQuery({ queryKey: ['child-photos', id], queryFn: () => api<{ data: ChildProfilePhoto[] }>(`/children/${id}/photos`) });
  const removePhoto = useMutation({ mutationFn: (photoId: string) => api(`/children/${id}/photos/${photoId}`, { method: 'DELETE' }), onSuccess: () => client.invalidateQueries({ queryKey: ['child-photos', id] }) });
  const removeChild = useMutation({ mutationFn: () => api(`/children/${id}`, { method: 'DELETE' }), onSuccess: async () => { await client.invalidateQueries({ queryKey: ['children'] }); router.replace('/(tabs)/children'); } });
  const align = { textAlign: isRtl ? 'right' : 'left' } as const;

  return <Screen>
    <Text style={[styles.title, align]}>{child.data?.data.name ?? l('ملف الطفل', 'Child profile')}</Text>
    <Text style={[styles.meta, align]}>{l('العمر', 'Age')}: {child.data?.data.age ?? l('غير محدد', 'Not specified')} · {child.data?.data.interests.join(isRtl ? '، ' : ', ')}</Text>
    <Text style={[styles.section, align]}>{l('الصور الخاصة القابلة لإعادة الاستخدام', 'Private reusable photos')}</Text>
    <Text style={[styles.note, align]}>{l('هذه الصور لا تظهر للعامة أو في التحليلات. حذفها لا يحذف النسخ المنفصلة المطلوبة لتنفيذ طلبات سابقة.', 'These photos are never public or included in analytics. Deleting them does not remove separate copies required to fulfil previous orders.')}</Text>
    <View style={[styles.grid, { flexDirection: isRtl ? 'row-reverse' : 'row' }]}>{photos.data?.data.map(photo => <View key={photo.id} style={styles.photoCard}>
      <AuthenticatedImage accessibilityLabel={l('صورة خاصة للطفل', 'Private child photo')} uri={photo.media_url} style={styles.photo} contentFit="cover" />
      <Pressable accessibilityRole="button" accessibilityLabel={l('حذف الصورة', 'Delete photo')} onPress={() => Alert.alert(l('حذف الصورة', 'Delete photo'), l('سيتم حذف الصورة من ملف الطفل نهائياً.', 'This photo will be permanently removed from the child profile.'), [{ text: l('رجوع', 'Back'), style: 'cancel' }, { text: l('حذف', 'Delete'), style: 'destructive', onPress: () => removePhoto.mutate(photo.id) }])}><Text style={styles.delete}>{l('حذف الصورة', 'Delete photo')}</Text></Pressable>
    </View>)}</View>
    <Button title={l('إضافة صور أو إنشاء هوية', 'Add photos or create an identity')} onPress={() => router.push('/identity')} />
    <Button title={l('حذف ملف الطفل', 'Delete child profile')} variant="ghost" onPress={() => Alert.alert(l('حذف ملف الطفل', 'Delete child profile'), l('سيتم حذف ملف الطفل وصوره القابلة لإعادة الاستخدام. الطلبات السابقة تبقى محفوظة حسب سياسة الاحتفاظ.', 'The child profile and reusable photos will be deleted. Previous orders remain according to the retention policy.'), [{ text: l('رجوع', 'Back'), style: 'cancel' }, { text: l('حذف', 'Delete'), style: 'destructive', onPress: () => removeChild.mutate() }])} />
  </Screen>;
}

const styles = StyleSheet.create({
  title: { fontSize: 29, fontWeight: '900', color: colors.text, marginTop: 28 }, meta: { color: colors.muted },
  section: { fontSize: 19, fontWeight: '900', color: colors.text }, note: { color: colors.muted, lineHeight: 23 },
  grid: { flexWrap: 'wrap', gap: 12 }, photoCard: { width: '47%', padding: 8, borderWidth: 1, borderColor: colors.border, borderRadius: radii.md, backgroundColor: '#fff', gap: 7 },
  photo: { width: '100%', aspectRatio: 1, borderRadius: radii.md }, delete: { textAlign: 'center', color: colors.danger, fontWeight: '800' },
});
