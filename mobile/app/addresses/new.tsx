import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { router } from 'expo-router';
import { useEffect, useState } from 'react';
import { Pressable, StyleSheet, Text } from 'react-native';
import { api } from '@/src/api/client';
import { Button } from '@/src/components/Button';
import { Field } from '@/src/components/Field';
import { Screen } from '@/src/components/Screen';
import { useI18n } from '@/src/i18n';
import { colors, radii } from '@/src/theme';

type Country = { id: number; name: string; governors?: never; governorates: { id: number; name: string; delivery_fee: number }[] };

export default function NewAddressScreen() {
  const { t, l, isRtl } = useI18n();
  const client = useQueryClient();
  const [value, setValue] = useState({ label: l('المنزل','Home'), recipient_name: '', phone: '', city: '', street: '', details: '', delivery_instructions: '' });
  const [countryId, setCountryId] = useState<number | null>(null);
  const [governorateId, setGovernorateId] = useState<number | null>(null);
  const bootstrap = useQuery({ queryKey: ['bootstrap'], queryFn: () => api<{ data: { delivery_countries: Country[] } }>('/bootstrap') });
  const countries = bootstrap.data?.data.delivery_countries ?? [];
  const country = countries.find(item => item.id === countryId);
  useEffect(() => { if (!countryId && countries[0]) setCountryId(countries[0].id); }, [countryId, countries]);
  useEffect(() => { if (country && !country.governorates.some(item => item.id === governorateId)) setGovernorateId(country.governorates[0]?.id ?? null); }, [country, governorateId]);
  const mutation = useMutation({ mutationFn: () => api('/addresses', { method: 'POST', body: JSON.stringify({ ...value, delivery_country_id: countryId, delivery_governorate_id: governorateId, is_default: true }) }), onSuccess: async () => { await client.invalidateQueries({ queryKey: ['addresses'] }); router.back(); } });

  return <Screen>
    <Text style={[styles.title, align(isRtl)]}>{t.addAddress}</Text>
    <Field label={l('اسم العنوان','Address label')} value={value.label} onChangeText={label => setValue({ ...value, label })} />
    <Field label={l('اسم المستلم','Recipient name')} value={value.recipient_name} onChangeText={recipient_name => setValue({ ...value, recipient_name })} />
    <Field label={t.phone} keyboardType="phone-pad" value={value.phone} onChangeText={phone => setValue({ ...value, phone })} />
    <Text style={[styles.label, align(isRtl)]}>{l('الدولة','Country')}</Text>
    {countries.map(item => <Pressable accessibilityLabel={item.name} accessibilityRole="radio" accessibilityState={{ checked: countryId === item.id }} key={item.id} onPress={() => setCountryId(item.id)} style={[styles.choice, countryId === item.id && styles.selected]}><Text style={[styles.choiceText, align(isRtl)]}>{item.name} {countryId === item.id ? '✓' : ''}</Text></Pressable>)}
    <Text style={[styles.label, align(isRtl)]}>{l('المحافظة','Governorate')}</Text>
    {country?.governorates.map(item => <Pressable accessibilityLabel={`${item.name}, ${item.delivery_fee} ${l('ج.م','EGP')}`} accessibilityRole="radio" accessibilityState={{ checked: governorateId === item.id }} key={item.id} onPress={() => setGovernorateId(item.id)} style={[styles.choice, governorateId === item.id && styles.selected]}><Text style={[styles.choiceText, align(isRtl)]}>{item.name} · {item.delivery_fee} {l('ج.م','EGP')} {governorateId === item.id ? '✓' : ''}</Text></Pressable>)}
    <Field label={l('المدينة أو المنطقة','City or area')} value={value.city} onChangeText={city => setValue({ ...value, city })} />
    <Field label={l('الشارع','Street')} value={value.street} onChangeText={street => setValue({ ...value, street })} />
    <Field label={l('تفاصيل العنوان','Address details')} value={value.details} onChangeText={details => setValue({ ...value, details })} multiline />
    <Field label={l('تعليمات التوصيل','Delivery instructions')} value={value.delivery_instructions} onChangeText={delivery_instructions => setValue({ ...value, delivery_instructions })} multiline />
    <Button title={t.save} disabled={!countryId || !governorateId || !value.recipient_name || !value.phone || !value.city || !value.street || !value.details || mutation.isPending} onPress={() => mutation.mutate()} />
    {mutation.error ? <Text style={styles.error}>{t.error}</Text> : null}
  </Screen>;
}

const align = (isRtl: boolean) => ({ textAlign: isRtl ? 'right' as const : 'left' as const });
const styles = StyleSheet.create({ title: { fontSize: 29, fontWeight: '900', color: colors.text, marginTop: 28 }, label: { fontWeight: '800', color: colors.text }, choice: { padding: 13, borderWidth: 1, borderColor: colors.border, borderRadius: radii.md, backgroundColor: '#fff' }, selected: { borderColor: colors.primary, backgroundColor: '#EEF2FF' }, choiceText: { color: colors.text, fontWeight: '700' }, error: { textAlign: 'auto', color: colors.danger } });
