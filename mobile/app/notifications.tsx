import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { router } from 'expo-router';
import { useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Switch, Text, View } from 'react-native';
import { api } from '@/src/api/client';
import type { DeviceInstallation, MobileNotification } from '@/src/api/types';
import { Button } from '@/src/components/Button';
import { Screen } from '@/src/components/Screen';
import { useI18n } from '@/src/i18n';
import { registerForPush } from '@/src/notifications/register';
import { colors, radii } from '@/src/theme';

export default function NotificationsScreen() {
  const { locale, t, l, isRtl } = useI18n();
  const client = useQueryClient();
  const [error, setError] = useState('');
  const devices = useQuery({ queryKey: ['devices'], queryFn: () => api<{ data: DeviceInstallation[] }>('/devices') });
  const notifications = useQuery({ queryKey: ['notifications'], queryFn: () => api<{ data: MobileNotification[]; meta: { unread: number } }>('/notifications') });
  const current = devices.data?.data.find(device => !device.revoked_at);
  const register = useMutation({ mutationFn: () => registerForPush(locale, current?.marketing_notifications ?? false), onSuccess: () => client.invalidateQueries({ queryKey: ['devices'] }), onError: value => setError(value instanceof Error ? value.message : t.error) });
  const update = useMutation({ mutationFn: (values: Partial<DeviceInstallation>) => api(`/devices/${current?.id}`, { method: 'PATCH', body: JSON.stringify(values) }), onSuccess: () => client.invalidateQueries({ queryKey: ['devices'] }) });
  const markRead = useMutation({ mutationFn: (id: string) => api(`/notifications/${id}/read`, { method: 'POST' }), onSuccess: () => client.invalidateQueries({ queryKey: ['notifications'] }) });
  const align = { textAlign: isRtl ? 'right' : 'left' } as const;

  if (devices.isLoading || notifications.isLoading) return <Screen style={styles.center}><ActivityIndicator size="large" color={colors.primary} /></Screen>;
  return <Screen>
    <Text style={[styles.title, align]}>{l('الإشعارات', 'Notifications')}</Text>
    {!current?.push_enabled ? <Button title={l('تفعيل إشعارات الطلب', 'Enable order notifications')} disabled={register.isPending} onPress={() => register.mutate()} /> : <View style={styles.settings}>
      <Setting label={l('إشعارات تشغيلية للطلبات', 'Operational order notifications')} value={current.operational_notifications} onValueChange={value => update.mutate({ operational_notifications: value })} isRtl={isRtl} />
      <Setting label={l('عروض وتوصيات تسويقية', 'Marketing offers and recommendations')} value={current.marketing_notifications} onValueChange={value => update.mutate({ marketing_notifications: value })} isRtl={isRtl} />
      <Text style={[styles.note, align]}>{l('يمكن إيقاف التسويق بشكل مستقل. تبقى الإشعارات داخل التطبيق متاحة دائماً.', 'Marketing can be disabled independently. In-app notifications remain available.')}</Text>
    </View>}
    {error ? <Text style={[styles.error, align]}>{error}</Text> : null}
    <View style={[styles.header, { flexDirection: isRtl ? 'row-reverse' : 'row' }]}><Text style={styles.badge}>{notifications.data?.meta.unread ?? 0} {l('غير مقروء', 'unread')}</Text><Text style={styles.section}>{l('آخر التحديثات', 'Latest updates')}</Text></View>
    {notifications.data?.data.map(notification => <Pressable accessibilityRole="button" accessibilityLabel={`${notification.title}. ${notification.body}`} key={notification.id} onPress={() => { if (!notification.read_at) markRead.mutate(notification.id); if (notification.data.order_id) router.push(`/order/${notification.data.order_id}`); }} style={[styles.notification, !notification.read_at && styles.unread]}>
      <Text style={[styles.notificationTitle, align]}>{notification.title}</Text><Text style={[styles.body, align]}>{notification.body}</Text><Text style={[styles.time, align]}>{new Date(notification.created_at).toLocaleString(locale === 'ar' ? 'ar-EG' : 'en-US')}</Text>
    </Pressable>)}
  </Screen>;
}

function Setting({ label, value, onValueChange, isRtl }: { label: string; value: boolean; onValueChange: (value: boolean) => void; isRtl: boolean }) {
  return <View style={[styles.settingRow, { flexDirection: isRtl ? 'row-reverse' : 'row' }]}><Switch accessibilityLabel={label} value={value} onValueChange={onValueChange} trackColor={{ true: colors.primary }} /><Text style={[styles.settingLabel, { textAlign: isRtl ? 'right' : 'left' }]}>{label}</Text></View>;
}

const styles = StyleSheet.create({
  center: { flexGrow: 1, justifyContent: 'center' }, title: { fontSize: 29, fontWeight: '900', color: colors.text, marginTop: 28 },
  settings: { padding: 16, borderRadius: radii.lg, backgroundColor: '#fff', borderWidth: 1, borderColor: colors.border, gap: 14 }, settingRow: { justifyContent: 'space-between', alignItems: 'center', gap: 12 },
  settingLabel: { color: colors.text, fontWeight: '800', flex: 1 }, note: { color: colors.muted, lineHeight: 22 }, header: { justifyContent: 'space-between', alignItems: 'center' },
  section: { fontSize: 19, fontWeight: '900', color: colors.text }, badge: { color: colors.primary, fontWeight: '800' }, notification: { padding: 16, borderRadius: radii.md, backgroundColor: '#fff', borderWidth: 1, borderColor: colors.border, gap: 7 },
  unread: { borderColor: colors.primary, backgroundColor: '#EEF2FF' }, notificationTitle: { fontWeight: '900', color: colors.text }, body: { color: colors.muted, lineHeight: 22 }, time: { color: '#94A3B8', fontSize: 12 }, error: { color: colors.danger },
});
