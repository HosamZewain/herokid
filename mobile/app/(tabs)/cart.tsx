import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { router } from 'expo-router';
import { useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from 'react-native';
import { api } from '@/src/api/client';
import { idempotencyKey } from '@/src/api/idempotency';
import type { MobileCart } from '@/src/api/types';
import { useAuth } from '@/src/auth/AuthProvider';
import { Button } from '@/src/components/Button';
import { Field } from '@/src/components/Field';
import { Screen } from '@/src/components/Screen';
import { useI18n } from '@/src/i18n';
import { colors, radii } from '@/src/theme';

export default function CartScreen() {
  const { user } = useAuth();
  const { t, l, isRtl } = useI18n();
  const client = useQueryClient();
  const [code, setCode] = useState('');
  const cart = useQuery({ queryKey: ['cart'], queryFn: () => api<{ data: MobileCart }>('/cart'), enabled: !!user });
  const refresh = () => client.invalidateQueries({ queryKey: ['cart'] });
  const update = useMutation({ mutationFn: ({ id, quantity }: { id: string; quantity: number }) => api(`/cart/items/${id}`, { method: 'PATCH', body: JSON.stringify({ quantity }) }), onSuccess: refresh });
  const remove = useMutation({ mutationFn: (id: string) => api(`/cart/items/${id}`, { method: 'DELETE' }), onSuccess: refresh });
  const duplicate = useMutation({ mutationFn: (id: string) => api(`/cart/items/${id}/duplicate`, { method: 'POST', body: JSON.stringify({ idempotency_key: idempotencyKey() }) }), onSuccess: refresh });
  const promo = useMutation({ mutationFn: () => api('/cart/promo-code', { method: 'PUT', body: JSON.stringify({ code }) }), onSuccess: refresh });
  const clearPromo = useMutation({ mutationFn: () => api('/cart/promo-code', { method: 'DELETE' }), onSuccess: refresh });

  if (!user) return <Screen style={styles.center}><Text style={styles.emoji}>🛒</Text><Text style={styles.empty}>{t.emptyCart}</Text><Button title={t.signIn} onPress={() => router.push('/auth/login')} /></Screen>;
  if (cart.isLoading) return <Screen style={styles.center}><ActivityIndicator color={colors.primary} size="large" /></Screen>;

  const data = cart.data?.data;
  return <Screen>
    <Text style={[styles.title, align(isRtl)]}>{t.cart}</Text>
    {data?.items.length === 0 ? <><Text style={styles.emoji}>🛒</Text><Text style={styles.empty}>{t.emptyCart}</Text><Button title={t.explore} onPress={() => router.push('/(tabs)/catalog')} /></> : data?.items.map(item => <View key={item.id} style={styles.item}>
      <Text style={[styles.itemTitle, align(isRtl)]}>{item.title}</Text>
      {item.child ? <Text style={[styles.meta, align(isRtl)]}>{l('للطفل','For')}: {item.child.name}</Text> : null}
      <View style={[styles.row, direction(isRtl)]}><Text style={styles.price}>{item.line_total} {l('ج.م','EGP')}</Text><View style={styles.quantity}>
        <Pressable accessibilityLabel={l('زيادة الكمية','Increase quantity')} accessibilityRole="button" disabled={item.type === 'story'} onPress={() => update.mutate({ id: item.id, quantity: item.quantity + 1 })}><Text style={styles.quantityButton}>＋</Text></Pressable>
        <Text style={styles.quantityValue}>{item.quantity}</Text>
        <Pressable accessibilityLabel={l('تقليل الكمية','Decrease quantity')} accessibilityRole="button" disabled={item.quantity <= 1 || item.type === 'story'} onPress={() => update.mutate({ id: item.id, quantity: item.quantity - 1 })}><Text style={styles.quantityButton}>−</Text></Pressable>
      </View></View>
      <View style={[styles.actions, direction(isRtl)]}><Pressable accessibilityRole="button" onPress={() => remove.mutate(item.id)}><Text style={styles.remove}>{t.remove}</Text></Pressable>{item.type !== 'product_add_on' ? <Pressable accessibilityRole="button" onPress={() => duplicate.mutate(item.id)}><Text style={styles.link}>{l('تكرار','Duplicate')}</Text></Pressable> : null}</View>
    </View>)}
    {data && data.items.length > 0 ? <><View style={styles.promo}><Field label={t.promoCode} value={code} onChangeText={setCode} /><Button title={t.apply} variant="ghost" disabled={!code || promo.isPending} onPress={() => promo.mutate()} />{data.promo_code ? <Text onPress={() => clearPromo.mutate()} style={styles.link}>{data.promo_code} ×</Text> : null}</View><View style={styles.totalBox}><Line label={l('الإجمالي الفرعي','Subtotal')} value={data.totals.subtotal} isRtl={isRtl} currency={l('ج.م','EGP')} />{data.totals.discount > 0 ? <Line label={l('الخصم','Discount')} value={-data.totals.discount} isRtl={isRtl} currency={l('ج.م','EGP')} /> : null}<Line label={l('الإجمالي قبل التوصيل','Total before delivery')} value={data.totals.total} strong isRtl={isRtl} currency={l('ج.م','EGP')} /></View><Button title={t.checkout} onPress={() => router.push('/checkout')} /></> : null}
    {promo.error || cart.error ? <Text style={styles.error}>{t.error}</Text> : null}
  </Screen>;
}

function Line({ label, value, strong = false, isRtl, currency }: { label: string; value: number; strong?: boolean; isRtl: boolean; currency: string }) {
  return <View style={[styles.row, direction(isRtl)]}><Text style={[styles.value, strong && styles.strong]}>{value} {currency}</Text><Text style={[styles.meta, strong && styles.strong, align(isRtl)]}>{label}</Text></View>;
}

const align = (isRtl: boolean) => ({ textAlign: isRtl ? 'right' as const : 'left' as const });
const direction = (isRtl: boolean) => ({ flexDirection: isRtl ? 'row-reverse' as const : 'row' as const });
const styles = StyleSheet.create({ center: { flexGrow: 1, justifyContent: 'center' }, title: { fontSize: 28, color: colors.text, fontWeight: '900', marginTop: 24 }, emoji: { fontSize: 82, textAlign: 'center' }, empty: { textAlign: 'center', color: colors.muted, fontSize: 17 }, item: { backgroundColor: '#fff', borderRadius: radii.lg, padding: 16, gap: 10, borderWidth: 1, borderColor: colors.border }, itemTitle: { fontSize: 18, fontWeight: '900', color: colors.text }, meta: { color: colors.muted }, row: { alignItems: 'center', justifyContent: 'space-between' }, price: { color: colors.primary, fontWeight: '900', fontSize: 18 }, quantity: { flexDirection: 'row', alignItems: 'center', gap: 10 }, quantityButton: { fontSize: 23, color: colors.primary, fontWeight: '900', padding: 5 }, quantityValue: { fontWeight: '900', color: colors.text }, actions: { gap: 22 }, remove: { color: colors.danger, fontWeight: '800' }, link: { color: colors.primary, fontWeight: '800' }, promo: { backgroundColor: '#fff', padding: 14, borderRadius: radii.md, gap: 10 }, totalBox: { backgroundColor: '#EEF2FF', borderRadius: radii.md, padding: 16, gap: 10 }, value: { color: colors.text }, strong: { fontWeight: '900', fontSize: 18, color: colors.text }, error: { color: colors.danger, textAlign: 'auto' } });
