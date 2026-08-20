import { useQuery } from '@tanstack/react-query';
import { router } from 'expo-router';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { api } from '@/src/api/client';
import type { OrderSummary } from '@/src/api/types';
import { useAuth } from '@/src/auth/AuthProvider';
import { Button } from '@/src/components/Button';
import { Screen } from '@/src/components/Screen';
import { useI18n } from '@/src/i18n';
import { colors, radii } from '@/src/theme';

export default function OrdersScreen() {
  const { t, l, isRtl } = useI18n();
  const { user } = useAuth();
  const { data } = useQuery({ queryKey: ['orders'], queryFn: () => api<{ data: OrderSummary[] }>('/orders'), enabled: !!user });
  const textAlign = { textAlign: isRtl ? 'right' as const : 'left' as const };
  const direction = { flexDirection: isRtl ? 'row-reverse' as const : 'row' as const };
  if (!user) return <Screen style={styles.center}><Text style={styles.icon}>📦</Text><Text style={styles.body}>{t.emptyOrders}</Text><Button title={t.signIn} onPress={() => router.push('/auth/login')} /></Screen>;
  return <Screen><Text style={[styles.title, textAlign]}>{t.orders}</Text>{data?.data.length === 0 ? <><Text style={styles.icon}>📦</Text><Text style={styles.body}>{t.emptyOrders}</Text></> : data?.data.map(order => <View key={order.id} style={styles.order}><View style={[styles.row, direction]}><Text style={styles.status}>{statusLabel(order.customer_status, l)}</Text><Text style={styles.number}>{order.number}</Text></View><Text style={[styles.meta, textAlign]}>{order.child_name ?? l('طلب HeroKid','HeroKid order')} · {order.product_count} {l('منتج','item')}</Text><View style={[styles.row, direction]}><Text style={styles.total}>{order.total} {l('ج.م','EGP')}</Text><Pressable accessibilityLabel={`${l('تفاصيل الطلب','Order details')} ${order.number}`} accessibilityRole="button" onPress={() => router.push(`/order/${order.id}`)}><Text style={styles.link}>{l('التفاصيل ←','Details →')}</Text></Pressable></View></View>)}</Screen>;
}

function statusLabel(status: string, l: (ar: string, en: string) => string) { return ({ awaiting_payment: l('بانتظار الدفع','Awaiting payment'), payment_confirmed: l('تم تأكيد الدفع','Payment confirmed'), under_review: l('قيد المراجعة','Under review'), identity_preparation: l('إعداد الهوية','Identity preparation'), content_production: l('إنتاج المحتوى','Content production'), design_ready_for_approval: l('التصميم جاهز','Design ready'), revision_requested: l('تم طلب تعديل','Revision requested'), approved_for_printing: l('معتمد للطباعة','Approved for printing'), printing: l('قيد الطباعة','Printing'), shipped: l('تم الشحن','Shipped'), delivered: l('تم التوصيل','Delivered'), cancelled: l('ملغي','Cancelled') } as Record<string, string>)[status] ?? status; }
const styles = StyleSheet.create({ center: { flexGrow: 1, justifyContent: 'center' }, icon: { fontSize: 84, textAlign: 'center' }, title: { color: colors.text, fontSize: 28, fontWeight: '900', marginTop: 24 }, body: { textAlign: 'center', color: colors.muted, fontSize: 17 }, order: { backgroundColor: '#fff', borderRadius: radii.lg, padding: 17, gap: 12, borderWidth: 1, borderColor: colors.border }, row: { justifyContent: 'space-between', alignItems: 'center' }, number: { color: colors.text, fontWeight: '900', fontSize: 17 }, status: { color: colors.primaryDark, backgroundColor: '#EEF2FF', borderRadius: radii.pill, paddingHorizontal: 10, paddingVertical: 6, overflow: 'hidden', fontWeight: '700' }, meta: { color: colors.muted }, total: { color: colors.primary, fontWeight: '900', fontSize: 18 }, link: { color: colors.primary, fontWeight: '800' } });
