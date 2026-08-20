import { router } from 'expo-router';
import { useState } from 'react';
import { StyleSheet, Text } from 'react-native';
import { api } from '@/src/api/client';
import { useAuth } from '@/src/auth/AuthProvider';
import { Button } from '@/src/components/Button';
import { Field } from '@/src/components/Field';
import { Screen } from '@/src/components/Screen';
import { useI18n } from '@/src/i18n';
import { colors } from '@/src/theme';

type OtpChallenge = { data: { challenge_id: string; expires_at: string } };

export default function OtpScreen() {
  const { verifyOtp } = useAuth();
  const { t } = useI18n();
  const [phone, setPhone] = useState('');
  const [name, setName] = useState('');
  const [code, setCode] = useState('');
  const [challengeId, setChallengeId] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  const requestCode = async () => {
    setBusy(true);
    setError('');
    try {
      const response = await api<OtpChallenge>('/auth/otp/request', {
        method: 'POST',
        body: JSON.stringify({ phone }),
      });
      setChallengeId(response.data.challenge_id);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : t.error);
    } finally {
      setBusy(false);
    }
  };

  const verify = async () => {
    if (!challengeId) return;
    setBusy(true);
    setError('');
    try {
      await verifyOtp({ challenge_id: challengeId, code, name: name || undefined });
      router.replace('/(tabs)');
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : t.error);
    } finally {
      setBusy(false);
    }
  };

  return (
    <Screen style={styles.center}>
      <Text style={styles.title}>{t.signInOtp}</Text>
      <Text style={styles.body}>{challengeId ? t.otpSent : t.otpInstructions}</Text>
      {!challengeId ? (
        <>
          <Field label={t.phone} keyboardType="phone-pad" value={phone} onChangeText={setPhone} />
          <Field label={t.nameOptional} value={name} onChangeText={setName} />
          <Button title={t.sendCode} disabled={busy || phone.trim().length < 10} onPress={requestCode} />
        </>
      ) : (
        <>
          <Field label={t.verificationCode} keyboardType="number-pad" maxLength={6} value={code} onChangeText={setCode} />
          <Button title={t.verifyAndContinue} disabled={busy || code.length !== 6} onPress={verify} />
          <Button title={t.changePhone} variant="ghost" disabled={busy} onPress={() => { setChallengeId(null); setCode(''); }} />
        </>
      )}
      {error ? <Text style={styles.error}>{error}</Text> : null}
      <Button title={t.signIn} variant="ghost" disabled={busy} onPress={() => router.back()} />
    </Screen>
  );
}

const styles = StyleSheet.create({
  center: { flexGrow: 1, justifyContent: 'center' },
  title: { textAlign: 'right', color: colors.text, fontSize: 28, fontWeight: '900' },
  body: { textAlign: 'right', color: colors.muted, lineHeight: 24 },
  error: { color: colors.danger, textAlign: 'right' },
});
