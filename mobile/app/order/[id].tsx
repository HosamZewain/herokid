import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { router, useLocalSearchParams } from 'expo-router';
import { ActivityIndicator, StyleSheet, Text, View } from 'react-native';
import { api } from '@/src/api/client';
import { idempotencyKey } from '@/src/api/idempotency';
import type { OrderDetail } from '@/src/api/types';
import { Button } from '@/src/components/Button';
import { Screen } from '@/src/components/Screen';
import { useI18n } from '@/src/i18n';
import { colors, radii } from '@/src/theme';

export default function OrderScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { l, isRtl } = useI18n();
  const client = useQueryClient();
  const { data, isLoading } = useQuery({ queryKey: ['order', id], queryFn: () => api<{ data: OrderDetail }>(`/orders/${id}`) });
  const reorder = useMutation({ mutationFn: () => api(`/orders/${id}/reorder`, { method: 'POST', body: JSON.stringify({ idempotency_key: idempotencyKey() }) }), onSuccess: async () => { await client.invalidateQueries({ queryKey: ['cart'] }); router.push('/(tabs)/cart'); } });
  if (isLoading || !data) return <Screen style={styles.center}><ActivityIndicator color={colors.primary} size="large" /></Screen>;
  const order = data.data;
  const hasPreview = ['design_ready_for_approval', 'revision_requested', 'approved_for_printing'].includes(order.customer_status);
  const align = { textAlign: isRtl ? 'right' : 'left' } as const;

  return <Screen>
    <Text style={[styles.title, align]}>{l('الطلب', 'Order')} {order.number}</Text>
    <View style={styles.summary}><Text style={[styles.status, align]}>{statusLabel(order.customer_status, l)}</Text><Text style={[styles.total, align]}>{order.total} {l('ج.م', 'EGP')}</Text></View>
    {hasPreview ? <Button title={order.customer_status === 'design_ready_for_approval' ? l('راجع التصميم واتخذ قرارك', 'Review the design') : l('عرض قرار المعاينة', 'View preview decision')} onPress={() => router.push(`/order/${id}/preview`)} /> : null}
    <Text style={[styles.heading, align]}>{l('المنتجات', 'Products')}</Text>
    {order.items.map(item => <View key={item.id} style={styles.card}><Text style={[styles.item, align]}>{item.title}</Text><Text style={[styles.meta, align]}>{item.quantity} × {item.unit_price} {l('ج.م', 'EGP')}</Text></View>)}
    <Text style={[styles.heading, align]}>{l('تتبع الطلب', 'Order tracking')}</Text>
    {order.timeline.map((event, index) => <View key={`${event.at}-${index}`} style={[styles.timeline, { flexDirection: isRtl ? 'row-reverse' : 'row' }]}><View style={styles.dot} /><View style={styles.event}><Text style={[styles.item, align]}>{statusLabel(event.status, l)}</Text>{event.message ? <Text style={[styles.meta, align]}>{event.message}</Text> : null}</View></View>)}
    {order.can_reorder ? <Button title={l('إعادة الطلب', 'Reorder')} disabled={reorder.isPending} onPress={() => reorder.mutate()} /> : null}
    {reorder.error ? <Text style={[styles.error, align]}>{reorder.error instanceof Error ? reorder.error.message : l('تعذر إعادة الطلب', 'Could not reorder')}</Text> : null}
  </Screen>;
}

function statusLabel(status: string, l: (ar: string, en: string) => string) { return ({ awaiting_payment: l('بانتظار الدفع', 'Awaiting payment'), payment_confirmed: l('تم تأكيد الدفع', 'Payment confirmed'), under_review: l('قيد المراجعة', 'Under review'), identity_preparation: l('إعداد الهوية', 'Identity preparation'), content_production: l('إنتاج المحتوى', 'Content production'), design_ready_for_approval: l('التصميم جاهز للمراجعة', 'Design ready for review'), revision_requested: l('تم طلب تعديل', 'Revision requested'), approved_for_printing: l('معتمد للطباعة', 'Approved for printing'), printing: l('قيد الطباعة', 'Printing'), shipped: l('تم الشحن', 'Shipped'), delivered: l('تم التوصيل', 'Delivered'), cancelled: l('ملغي', 'Cancelled') } as Record<string, string>)[status] ?? status; }
const styles = StyleSheet.create({ center: { flexGrow: 1, justifyContent: 'center' }, title: { color: colors.text, fontSize: 28, fontWeight: '900', marginTop: 30 }, summary: { backgroundColor: colors.primary, borderRadius: radii.lg, padding: 20, gap: 8 }, status: { color: '#E0E7FF', fontWeight: '800' }, total: { color: '#fff', fontWeight: '900', fontSize: 25 }, heading: { color: colors.text, fontSize: 20, fontWeight: '900' }, card: { backgroundColor: '#fff', borderRadius: radii.md, padding: 15, borderWidth: 1, borderColor: colors.border }, item: { color: colors.text, fontWeight: '800' }, meta: { color: colors.muted, marginTop: 4 }, timeline: { gap: 12, alignItems: 'flex-start' }, dot: { width: 13, height: 13, borderRadius: 7, backgroundColor: colors.primary, marginTop: 5 }, event: { flex: 1 }, error: { color: colors.danger } });
