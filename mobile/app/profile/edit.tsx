import { useState } from 'react';
import { router } from 'expo-router';
import { StyleSheet, Text } from 'react-native';
import { api } from '@/src/api/client';
import { useAuth } from '@/src/auth/AuthProvider';
import { Button } from '@/src/components/Button';
import { Field } from '@/src/components/Field';
import { Screen } from '@/src/components/Screen';
import { useI18n } from '@/src/i18n';
import { colors } from '@/src/theme';

export default function EditProfileScreen() {
  const { user, refreshUser } = useAuth();
  const { t, l, isRtl } = useI18n();
  const [values, setValues] = useState({ name: user?.name ?? '', email: user?.email ?? '', phone: user?.phone ?? '' });
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const save = async () => {
    setBusy(true); setError('');
    try {
      await api('/me', { method: 'PATCH', body: JSON.stringify(values) });
      await refreshUser();
      router.back();
    } catch (reason) { setError(reason instanceof Error ? reason.message : t.error); } finally { setBusy(false); }
  };
  return <Screen>
    <Text style={[styles.title, { textAlign: isRtl ? 'right' : 'left' }]}>{l('تعديل بيانات الحساب', 'Edit account details')}</Text>
    <Field label={t.name} value={values.name} onChangeText={name => setValues({ ...values, name })} />
    <Field label={t.email} autoCapitalize="none" keyboardType="email-address" value={values.email} onChangeText={email => setValues({ ...values, email })} />
    <Field label={t.phone} keyboardType="phone-pad" value={values.phone} onChangeText={phone => setValues({ ...values, phone })} />
    <Text style={[styles.note, { textAlign: isRtl ? 'right' : 'left' }]}>{l('عند تغيير البريد الإلكتروني سيحتاج إلى التحقق مرة أخرى.', 'Changing your email requires verification again.')}</Text>
    {error ? <Text style={styles.error}>{error}</Text> : null}
    <Button title={l('حفظ التغييرات', 'Save changes')} disabled={busy || !values.name.trim() || !values.email.trim()} onPress={save} />
    <Button title={l('رجوع', 'Back')} variant="ghost" onPress={() => router.back()} />
  </Screen>;
}

const styles = StyleSheet.create({ title: { color: colors.text, fontSize: 29, fontWeight: '900', marginTop: 28 }, note: { color: colors.muted, lineHeight: 22 }, error: { color: colors.danger } });
