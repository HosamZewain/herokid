import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { Alert, Pressable, StyleSheet, Text, View } from 'react-native';
import { api } from '@/src/api/client';
import type { ConsentRecord, MobileSession, PrivacyRequest } from '@/src/api/types';
import { Button } from '@/src/components/Button';
import { Field } from '@/src/components/Field';
import { Screen } from '@/src/components/Screen';
import { useI18n } from '@/src/i18n';
import { colors, radii } from '@/src/theme';

export default function PrivacyScreen() {
  const { t, l, isRtl, locale } = useI18n();
  const client = useQueryClient();
  const [password, setPassword] = useState('');
  const [reason, setReason] = useState('');
  const sessions = useQuery({ queryKey: ['sessions'], queryFn: () => api<{ data: MobileSession[] }>('/sessions') });
  const requests = useQuery({ queryKey: ['privacy-requests'], queryFn: () => api<{ data: PrivacyRequest[] }>('/privacy/requests') });
  const consents = useQuery({ queryKey: ['consents'], queryFn: () => api<{ data: ConsentRecord[] }>('/privacy/consents') });
  const refresh = () => client.invalidateQueries({ queryKey: ['privacy-requests'] });
  const revoke = useMutation({ mutationFn: (id: number) => api(`/sessions/${id}`, { method: 'DELETE' }), onSuccess: () => client.invalidateQueries({ queryKey: ['sessions'] }) });
  const create = useMutation({ mutationFn: (payload: Record<string, unknown>) => api('/privacy/requests', { method: 'POST', body: JSON.stringify(payload) }), onSuccess: refresh });
  const cancel = useMutation({ mutationFn: (id: string) => api(`/privacy/requests/${id}/cancel`, { method: 'POST' }), onSuccess: refresh });
  const deletion = requests.data?.data.find(item => item.type === 'account_deletion' && ['pending', 'processing'].includes(item.status));
  const align = { textAlign: isRtl ? 'right' : 'left' } as const;
  const date = (value: string) => new Date(value).toLocaleDateString(locale === 'ar' ? 'ar-EG' : 'en-US');

  return <Screen>
    <Text style={[styles.title, align]}>{l('الخصوصية والأمان', 'Privacy and security')}</Text>
    <Text style={[styles.section, align]}>{l('جلسات الأجهزة', 'Device sessions')}</Text>
    {sessions.data?.data.map(session => <View style={styles.card} key={session.id}>
      <Text style={[styles.cardTitle, align]}>{session.name}{session.current ? l(' · هذا الجهاز', ' · This device') : ''}</Text>
      <Text style={[styles.meta, align]}>{l('ينتهي', 'Expires')}: {session.expires_at ? date(session.expires_at) : l('غير محدد', 'Not specified')}</Text>
      {!session.current ? <Pressable accessibilityRole="button" accessibilityLabel={l('تسجيل خروج هذا الجهاز', 'Sign out this device')} onPress={() => revoke.mutate(session.id)}><Text style={[styles.dangerLink, align]}>{l('تسجيل خروج هذا الجهاز', 'Sign out this device')}</Text></Pressable> : null}
    </View>)}
    <Text style={[styles.section, align]}>{l('سجل الموافقات', 'Consent history')}</Text>
    <Text style={[styles.meta, align]}>{l(`لديك ${consents.data?.data.length ?? 0} سجل موافقة محفوظ. لا تتضمن السجلات أسماء الأطفال أو الصور.`, `You have ${consents.data?.data.length ?? 0} saved consent records. They do not contain child names or photos.`)}</Text>
    <Text style={[styles.section, align]}>{l('طلب نسخة من بياناتك', 'Request a copy of your data')}</Text>
    <Button title={l('طلب تصدير البيانات', 'Request data export')} variant="ghost" onPress={() => create.mutate({ request_type: 'data_export' })} />
    <Text style={[styles.section, align]}>{l('حذف الحساب', 'Delete account')}</Text>
    {deletion ? <View style={styles.warning}><Text style={[styles.warningTitle, align]}>{l('طلب الحذف قيد التنفيذ', 'Deletion request in progress')}</Text><Text style={[styles.meta, align]}>{l('الموعد المتوقع', 'Expected date')}: {deletion.due_at ? date(deletion.due_at) : l('خلال 30 يوماً', 'Within 30 days')}</Text>{deletion.status === 'pending' ? <Pressable accessibilityRole="button" onPress={() => cancel.mutate(deletion.id)}><Text style={[styles.link, align]}>{l('إلغاء طلب الحذف', 'Cancel deletion request')}</Text></Pressable> : null}</View> : <>
      <Text style={[styles.meta, align]}>{l('سيشمل الطلب الحساب وملفات الأطفال والصور القابلة لإعادة الاستخدام والجلسات، مع الاحتفاظ بما يلزم للطلبات والالتزامات القانونية فقط.', 'The request covers your account, child profiles, reusable photos, and sessions. Only data required for orders and legal obligations will be retained.')}</Text>
      <Field label={l('سبب الطلب (اختياري)', 'Reason (optional)')} value={reason} onChangeText={setReason} multiline /><Field label={t.password} value={password} onChangeText={setPassword} secureTextEntry />
      <Button title={l('طلب حذف الحساب', 'Request account deletion')} variant="ghost" disabled={!password || create.isPending} onPress={() => Alert.alert(l('تأكيد حذف الحساب', 'Confirm account deletion'), l('سيتم جدولة حذف الحساب. هل تريد المتابعة؟', 'Your account deletion will be scheduled. Continue?'), [{ text: l('رجوع', 'Back'), style: 'cancel' }, { text: l('تأكيد', 'Confirm'), style: 'destructive', onPress: () => create.mutate({ request_type: 'account_deletion', password, confirmation: 'DELETE_MY_ACCOUNT', reason }) }])} />
    </>}
    {create.error ? <Text style={[styles.error, align]}>{t.error}</Text> : null}
  </Screen>;
}

const styles = StyleSheet.create({
  title: { fontSize: 29, fontWeight: '900', color: colors.text, marginTop: 28 }, section: { fontSize: 19, fontWeight: '900', color: colors.text, marginTop: 6 }, card: { padding: 15, borderRadius: radii.md, borderWidth: 1, borderColor: colors.border, backgroundColor: '#fff', gap: 6 }, cardTitle: { fontWeight: '900', color: colors.text }, meta: { color: colors.muted, lineHeight: 23 }, dangerLink: { color: colors.danger, fontWeight: '800' }, warning: { padding: 16, borderRadius: radii.md, backgroundColor: '#FFF7ED', gap: 7 }, warningTitle: { color: '#9A3412', fontWeight: '900' }, link: { color: colors.primary, fontWeight: '800' }, error: { color: colors.danger },
});
