import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { router } from 'expo-router';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { api } from '@/src/api/client';
import type { CustomerAddress } from '@/src/api/types';
import { Button } from '@/src/components/Button';
import { Screen } from '@/src/components/Screen';
import { useI18n } from '@/src/i18n';
import { colors, radii } from '@/src/theme';

export default function AddressesScreen() {
  const { t, l, isRtl } = useI18n();
  const client = useQueryClient();
  const { data } = useQuery({ queryKey: ['addresses'], queryFn: () => api<{ data: CustomerAddress[] }>('/addresses') });
  const remove = useMutation({ mutationFn: (id: string) => api(`/addresses/${id}`, { method: 'DELETE' }), onSuccess: () => client.invalidateQueries({ queryKey: ['addresses'] }) });
  const textAlign = { textAlign: isRtl ? 'right' as const : 'left' as const };
  return <Screen><Text style={[styles.title, textAlign]}>{t.addresses}</Text>{data?.data.map(address => <View style={styles.card} key={address.id}><Text style={[styles.name, textAlign]}>{address.label ?? address.recipient_name}{address.is_default ? l(' · الافتراضي',' · Default') : ''}</Text><Text style={[styles.meta, textAlign]}>{address.city}، {address.street}، {address.details}</Text><Pressable accessibilityLabel={`${t.remove} ${address.label ?? address.recipient_name}`} accessibilityRole="button" onPress={() => remove.mutate(address.id)}><Text style={[styles.remove, textAlign]}>{t.remove}</Text></Pressable></View>)}<Button title={t.addAddress} onPress={() => router.push('/addresses/new')} /></Screen>;
}

const styles = StyleSheet.create({ title: { fontSize: 29, fontWeight: '900', color: colors.text, marginTop: 28 }, card: { padding: 16, borderWidth: 1, borderColor: colors.border, borderRadius: radii.md, backgroundColor: '#fff', gap: 8 }, name: { fontWeight: '900', color: colors.text, fontSize: 17 }, meta: { color: colors.muted, lineHeight: 23 }, remove: { fontWeight: '800', color: colors.danger } });
