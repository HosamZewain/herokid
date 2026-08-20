import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import * as FileSystem from 'expo-file-system/legacy';
import { router, useLocalSearchParams } from 'expo-router';
import * as Sharing from 'expo-sharing';
import { useState } from 'react';
import { ActivityIndicator, Alert, StyleSheet, Text, View } from 'react-native';
import { API_URL, api, getToken } from '@/src/api/client';
import type { OrderPreview } from '@/src/api/types';
import { Button } from '@/src/components/Button';
import { Field } from '@/src/components/Field';
import { Screen } from '@/src/components/Screen';
import { useI18n } from '@/src/i18n';
import { colors, radii } from '@/src/theme';

export default function PreviewScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { t, l, isRtl } = useI18n();
  const client = useQueryClient();
  const [page, setPage] = useState('1');
  const [comments, setComments] = useState('');
  const [opening, setOpening] = useState(false);
  const preview = useQuery({ queryKey: ['order-preview', id], queryFn: () => api<{ data: OrderPreview }>(`/orders/${id}/preview`) });
  const refresh = async () => { await Promise.all([client.invalidateQueries({ queryKey: ['order-preview', id] }), client.invalidateQueries({ queryKey: ['order', id] }), client.invalidateQueries({ queryKey: ['orders'] })]); };
  const approve = useMutation({ mutationFn: () => api(`/orders/${id}/preview/approve`, { method: 'POST', body: JSON.stringify({ preview_version_id: preview.data?.data.version.id }) }), onSuccess: refresh });
  const revision = useMutation({ mutationFn: () => api(`/orders/${id}/preview/revision`, { method: 'POST', body: JSON.stringify({ preview_version_id: preview.data?.data.version.id, page_number: Number(page), comments }) }), onSuccess: refresh });
  const open = async () => {
    setOpening(true);
    try {
      const token = await getToken();
      const uri = `${FileSystem.cacheDirectory}herokid-order-${id}-preview-v${preview.data?.data.version.number}.pdf`;
      const result = await FileSystem.downloadAsync(`${API_URL}/orders/${id}/preview/document`, uri, { headers: token ? { Authorization: `Bearer ${token}`, Accept: 'application/pdf' } : { Accept: 'application/pdf' } });
      if (result.status !== 200 && result.status !== 206) throw new Error('Preview download failed');
      if (await Sharing.isAvailableAsync()) await Sharing.shareAsync(result.uri, { mimeType: 'application/pdf', dialogTitle: l('معاينة HeroKid', 'HeroKid preview') });
      else Alert.alert(l('تم تنزيل المعاينة', 'Preview downloaded'), result.uri);
    } catch { Alert.alert(t.error); } finally { setOpening(false); }
  };
  if (preview.isLoading || !preview.data) return <Screen style={styles.center}><ActivityIndicator color={colors.primary} size="large" /></Screen>;
  const data = preview.data.data;
  const align = { textAlign: isRtl ? 'right' : 'left' } as const;

  return <Screen>
    <Text style={[styles.title, align]}>{l('معاينة الطلب', 'Order preview')} {data.order_number}</Text>
    <View style={styles.version}><Text style={[styles.versionTitle, align]}>{l('النسخة', 'Version')} {data.version.number}</Text><Text style={[styles.meta, align]}>{data.version.page_count} {l('صفحة', 'pages')} · {(data.version.file_size / 1024 / 1024).toFixed(1)} MB</Text><Text style={[styles.hash, align]}>{l('مرجع النسخة', 'Version reference')}: {data.version.checksum.slice(0, 12)}</Text></View>
    <Button title={opening ? l('جارٍ فتح المعاينة…', 'Opening preview…') : l('فتح المعاينة الآمنة', 'Open secure preview')} disabled={opening} onPress={open} />
    {data.decision ? <View style={styles.decision}><Text style={[styles.decisionTitle, align]}>{data.decision.type === 'approved' ? l('تم اعتماد هذه النسخة', 'This version is approved') : l('تم طلب تعديل', 'Revision requested')}</Text>{data.decision.page_number ? <Text style={[styles.meta, align]}>{l('الصفحة', 'Page')} {data.decision.page_number}</Text> : null}{data.decision.comments ? <Text style={[styles.comments, align]}>{data.decision.comments}</Text> : null}</View> : null}
    {data.can_decide ? <><View style={styles.warning}><Text style={[styles.warningTitle, align]}>{l('راجع جميع الصفحات قبل الاعتماد', 'Review every page before approval')}</Text><Text style={[styles.warningText, align]}>{l('بعد الاعتماد قد لا يمكن تعديل التصميم. أي نسخة جديدة ستطلب موافقة جديدة.', 'After approval, the design may no longer be editable. Every new version requires a new approval.')}</Text></View>
      <Button title={l('اعتماد النسخة للطباعة', 'Approve version for printing')} onPress={() => Alert.alert(l('اعتماد نهائي', 'Final approval'), l('هل راجعت كل الصفحات وتوافق على طباعة هذه النسخة؟', 'Have you reviewed every page and approved this version for printing?'), [{ text: l('رجوع', 'Back'), style: 'cancel' }, { text: l('اعتماد', 'Approve'), onPress: () => approve.mutate() }])} />
      <Text style={[styles.section, align]}>{l('أو اطلب تعديلاً', 'Or request a revision')}</Text><Field label={l('رقم الصفحة', 'Page number')} keyboardType="number-pad" value={page} onChangeText={setPage} /><Field label={l('التعديل المطلوب', 'Requested change')} value={comments} onChangeText={setComments} multiline /><Button title={l('إرسال طلب التعديل', 'Submit revision request')} variant="ghost" disabled={!comments.trim() || Number(page) < 1 || Number(page) > data.version.page_count || revision.isPending} onPress={() => revision.mutate()} />
    </> : null}
    <Button title={l('العودة إلى الطلب', 'Back to order')} variant="ghost" onPress={() => router.back()} />
    {approve.error || revision.error ? <Text style={[styles.error, align]}>{t.error}</Text> : null}
  </Screen>;
}

const styles = StyleSheet.create({
  center: { flexGrow: 1, justifyContent: 'center' }, title: { fontSize: 27, fontWeight: '900', color: colors.text, marginTop: 28 }, version: { backgroundColor: '#EEF2FF', padding: 17, borderRadius: radii.lg, gap: 6 }, versionTitle: { fontSize: 21, fontWeight: '900', color: colors.primaryDark }, meta: { color: colors.muted }, hash: { color: '#64748B', fontSize: 12 }, warning: { backgroundColor: '#FFF7ED', padding: 16, borderRadius: radii.md, gap: 6 }, warningTitle: { fontWeight: '900', color: '#9A3412' }, warningText: { color: '#9A3412', lineHeight: 23 }, section: { fontSize: 19, fontWeight: '900', color: colors.text }, decision: { backgroundColor: '#F0FDF4', padding: 16, borderRadius: radii.md, gap: 6 }, decisionTitle: { color: '#166534', fontWeight: '900' }, comments: { color: colors.text, lineHeight: 23 }, error: { color: colors.danger },
});
