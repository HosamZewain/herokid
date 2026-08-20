import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { router } from 'expo-router';
import { useEffect, useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from 'react-native';
import { api } from '@/src/api/client';
import { idempotencyKey } from '@/src/api/idempotency';
import type { CheckoutResult, CustomerAddress, MobileCart } from '@/src/api/types';
import { Button } from '@/src/components/Button';
import { Screen } from '@/src/components/Screen';
import { useI18n } from '@/src/i18n';
import { colors, radii } from '@/src/theme';

type PaymentMethod = 'cash_on_delivery'|'card'|'mobile_wallet';

export default function CheckoutScreen() {
  const { t, l, isRtl } = useI18n();
  const client = useQueryClient();
  const [addressId, setAddressId] = useState('');
  const [payment, setPayment] = useState<PaymentMethod>('cash_on_delivery');
  const [accepted, setAccepted] = useState(false);
  const [result, setResult] = useState<CheckoutResult | null>(null);
  const addresses = useQuery({ queryKey: ['addresses'], queryFn: () => api<{ data: CustomerAddress[] }>('/addresses') });
  const cart = useQuery({ queryKey: ['cart'], queryFn: () => api<{ data: MobileCart }>('/cart') });

  useEffect(() => { if (!addressId && addresses.data?.data[0]) setAddressId(addresses.data.data[0].id); }, [addressId, addresses.data]);
  const checkout = useMutation({
    mutationFn: () => api<{ data: CheckoutResult }>('/checkout', { method: 'POST', body: JSON.stringify({ address_id: addressId, payment_method: payment, terms_accepted: accepted, image_processing_consent: accepted, terms_document_version: '2026-08-03', consent_document_version: '2026-08-03', idempotency_key: idempotencyKey() }) }),
    onSuccess: async response => { setResult(response.data); if (response.data.status === 'completed') await Promise.all([client.invalidateQueries({ queryKey: ['cart'] }), client.invalidateQueries({ queryKey: ['orders'] })]); },
  });

  if (addresses.isLoading || cart.isLoading) return <Screen style={styles.center}><ActivityIndicator size="large" color={colors.primary} /></Screen>;
  if (result?.status === 'completed') return <Screen style={styles.center}><Text style={styles.emoji}>🎉</Text><Text style={styles.success}>{l('تم تأكيد طلبك','Your order is confirmed')}</Text><Text style={styles.body}>{l('رقم الطلب','Order number')}: {result.orders.map(order => order.number).join(l('، ',', '))}</Text><Button title={t.orders} onPress={() => router.replace('/(tabs)/orders')} /></Screen>;

  return <Screen>
    <Text style={[styles.title, align(isRtl)]}>{t.checkout}</Text>
    <Text style={[styles.section, align(isRtl)]}>{t.addresses}</Text>
    {addresses.data?.data.map(address => <Pressable accessibilityLabel={l(`استخدام عنوان ${address.label ?? address.recipient_name}`,`Use ${address.label ?? address.recipient_name}`)} accessibilityRole="radio" accessibilityState={{ checked: addressId === address.id }} key={address.id} onPress={() => setAddressId(address.id)} style={[styles.card, addressId === address.id && styles.selected]}><View style={[styles.row, direction(isRtl)]}><Text style={styles.check}>{addressId === address.id ? '✓' : '○'}</Text><Text style={[styles.cardTitle, align(isRtl)]}>{address.label ?? address.recipient_name}</Text></View><Text style={[styles.meta, align(isRtl)]}>{address.city} · {address.street}</Text></Pressable>)}
    <Button title={t.addAddress} variant="ghost" onPress={() => router.push('/addresses/new')} />
    <Text style={[styles.section, align(isRtl)]}>{l('طريقة الدفع','Payment method')}</Text>
    <PaymentChoice value="cash_on_delivery" current={payment} label={l('الدفع عند الاستلام','Cash on delivery')} onPress={setPayment} isRtl={isRtl} />
    <PaymentChoice value="card" current={payment} label={l('بطاقة بنكية','Bank card')} onPress={setPayment} isRtl={isRtl} />
    <PaymentChoice value="mobile_wallet" current={payment} label={l('محفظة إلكترونية','Mobile wallet')} onPress={setPayment} isRtl={isRtl} />
    <Text style={[styles.note, align(isRtl)]}>{l('الدفع الإلكتروني لا يُعتمد إلا بعد تأكيد مزود الدفع من الخادم.','Online payment is confirmed only by the server-side payment provider.')}</Text>
    <Pressable accessibilityLabel={t.terms} accessibilityRole="checkbox" accessibilityState={{ checked: accepted }} onPress={() => setAccepted(value => !value)} style={[styles.consent, direction(isRtl), accepted && styles.selected]}><Text style={styles.check}>{accepted ? '✓' : '○'}</Text><Text style={[styles.consentText, align(isRtl)]}>{t.terms}</Text></Pressable>
    {cart.data ? <View style={styles.total}><Text style={[styles.totalValue, align(isRtl)]}>{cart.data.data.totals.total} {l('ج.م','EGP')} + {l('التوصيل','delivery')}</Text><Text style={[styles.meta, align(isRtl)]}>{l('سيحسب الخادم رسوم عنوان التوصيل قبل التأكيد.','The server calculates the selected address delivery fee before confirmation.')}</Text></View> : null}
    <Button title={t.placeOrder} disabled={!addressId || !accepted || checkout.isPending} onPress={() => checkout.mutate()} />
    {result?.status === 'payment_configuration_required' ? <View style={styles.warning}><Text style={[styles.warningTitle, align(isRtl)]}>{l('الدفع الإلكتروني غير مُعد بعد','Online payment is not configured')}</Text><Text style={styles.body}>{result.payment.message}</Text></View> : null}
    {checkout.error ? <Text style={styles.error}>{t.error}</Text> : null}
  </Screen>;
}

function PaymentChoice({ value, current, label, onPress, isRtl }: { value: PaymentMethod; current: PaymentMethod; label: string; onPress: (value: PaymentMethod) => void; isRtl: boolean }) {
  return <Pressable accessibilityLabel={label} accessibilityRole="radio" accessibilityState={{ checked: current === value }} onPress={() => onPress(value)} style={[styles.card, current === value && styles.selected]}><View style={[styles.row, direction(isRtl)]}><Text style={styles.check}>{current === value ? '✓' : '○'}</Text><Text style={[styles.cardTitle, align(isRtl)]}>{label}</Text></View></Pressable>;
}

const align = (isRtl: boolean) => ({ textAlign: isRtl ? 'right' as const : 'left' as const });
const direction = (isRtl: boolean) => ({ flexDirection: isRtl ? 'row-reverse' as const : 'row' as const });
const styles = StyleSheet.create({ center: { flexGrow: 1, justifyContent: 'center' }, title: { fontSize: 29, color: colors.text, fontWeight: '900', marginTop: 28 }, section: { fontSize: 19, color: colors.text, fontWeight: '900' }, card: { backgroundColor: '#fff', borderWidth: 1, borderColor: colors.border, borderRadius: radii.md, padding: 14, gap: 7 }, selected: { borderColor: colors.primary, backgroundColor: '#EEF2FF' }, row: { justifyContent: 'space-between', alignItems: 'center' }, check: { color: colors.primary, fontWeight: '900', fontSize: 20 }, cardTitle: { color: colors.text, fontWeight: '800' }, meta: { color: colors.muted }, note: { color: colors.muted, lineHeight: 22 }, consent: { gap: 12, padding: 16, borderWidth: 1, borderColor: colors.border, borderRadius: radii.md }, consentText: { flex: 1, color: colors.text, lineHeight: 24 }, total: { backgroundColor: '#FFFBEB', padding: 16, borderRadius: radii.md, gap: 5 }, totalValue: { color: '#92400E', fontSize: 20, fontWeight: '900' }, emoji: { fontSize: 88, textAlign: 'center' }, success: { textAlign: 'center', fontSize: 27, fontWeight: '900', color: colors.text }, body: { textAlign: 'center', color: colors.muted, lineHeight: 24 }, warning: { backgroundColor: '#FFF7ED', padding: 16, borderRadius: radii.md, gap: 8 }, warningTitle: { color: '#9A3412', fontWeight: '900' }, error: { color: colors.danger, textAlign: 'auto' } });
