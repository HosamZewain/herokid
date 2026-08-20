import * as AppleAuthentication from 'expo-apple-authentication';
import * as Google from 'expo-auth-session/providers/google';
import { router } from 'expo-router';
import * as WebBrowser from 'expo-web-browser';
import { useEffect, useState } from 'react';
import { Platform, StyleSheet, Text } from 'react-native';
import { useAuth } from '@/src/auth/AuthProvider';
import { Button } from '@/src/components/Button';
import { Field } from '@/src/components/Field';
import { Screen } from '@/src/components/Screen';
import { useI18n } from '@/src/i18n';
import { colors } from '@/src/theme';

WebBrowser.maybeCompleteAuthSession();

const googleIds = {
  iosClientId: process.env.EXPO_PUBLIC_GOOGLE_IOS_CLIENT_ID,
  androidClientId: process.env.EXPO_PUBLIC_GOOGLE_ANDROID_CLIENT_ID,
  webClientId: process.env.EXPO_PUBLIC_GOOGLE_WEB_CLIENT_ID,
};
const googleConfigured = Boolean(googleIds.iosClientId && googleIds.androidClientId && googleIds.webClientId);

export default function LoginScreen() {
  const { login, loginWithSocial } = useAuth();
  const { t } = useI18n();
  const [value, setValue] = useState({ login: '', password: '' });
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [, googleResponse, promptGoogle] = Google.useIdTokenAuthRequest({
    iosClientId: googleIds.iosClientId ?? 'not-configured.apps.googleusercontent.com',
    androidClientId: googleIds.androidClientId ?? 'not-configured.apps.googleusercontent.com',
    webClientId: googleIds.webClientId ?? 'not-configured.apps.googleusercontent.com',
    selectAccount: true,
  });

  useEffect(() => {
    if (googleResponse?.type !== 'success') return;
    const idToken = googleResponse.params.id_token ?? googleResponse.authentication?.idToken;
    if (!idToken) {
      setError(t.socialTokenMissing);
      return;
    }
    setBusy(true);
    loginWithSocial({ provider: 'google', id_token: idToken })
      .then(() => router.replace('/(tabs)'))
      .catch((reason) => setError(reason instanceof Error ? reason.message : t.error))
      .finally(() => setBusy(false));
  }, [googleResponse]);

  const submit = async () => {
    setBusy(true);
    setError('');
    try {
      await login(value);
      router.replace('/(tabs)');
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : t.error);
    } finally {
      setBusy(false);
    }
  };

  const googleSignIn = async () => {
    setError('');
    if (!googleConfigured) {
      setError(t.googleNotConfigured);
      return;
    }
    await promptGoogle();
  };

  const appleSignIn = async () => {
    setError('');
    setBusy(true);
    try {
      const credential = await AppleAuthentication.signInAsync({
        requestedScopes: [
          AppleAuthentication.AppleAuthenticationScope.FULL_NAME,
          AppleAuthentication.AppleAuthenticationScope.EMAIL,
        ],
      });
      if (!credential.identityToken) throw new Error(t.socialTokenMissing);
      const name = credential.fullName ? AppleAuthentication.formatFullName(credential.fullName) : undefined;
      await loginWithSocial({ provider: 'apple', id_token: credential.identityToken, name });
      router.replace('/(tabs)');
    } catch (reason) {
      if ((reason as { code?: string }).code !== 'ERR_REQUEST_CANCELED') {
        setError(reason instanceof Error ? reason.message : t.error);
      }
    } finally {
      setBusy(false);
    }
  };

  return (
    <Screen style={styles.center}>
      <Text style={styles.logo}>HEROKID</Text>
      <Text style={styles.title}>{t.signIn}</Text>
      <Field label={t.emailOrPhone} autoCapitalize="none" value={value.login} onChangeText={(loginValue) => setValue({ ...value, login: loginValue })} />
      <Field label={t.password} secureTextEntry value={value.password} onChangeText={(password) => setValue({ ...value, password })} />
      {error ? <Text style={styles.error}>{error}</Text> : null}
      <Button title={t.signIn} disabled={busy} onPress={submit} />
      <Button title={t.signInOtp} variant="ghost" disabled={busy} onPress={() => router.push('/auth/otp')} />
      <Text style={styles.or}>{t.or}</Text>
      <Button title={t.continueGoogle} variant="ghost" disabled={busy} onPress={googleSignIn} />
      {Platform.OS === 'ios' ? <Button title={t.continueApple} variant="ghost" disabled={busy} onPress={appleSignIn} /> : null}
      <Button title={t.register} variant="ghost" onPress={() => router.replace('/auth/register')} />
    </Screen>
  );
}

const styles = StyleSheet.create({
  center: { flexGrow: 1, justifyContent: 'center' },
  logo: { textAlign: 'center', color: colors.primary, fontWeight: '900', letterSpacing: 3 },
  title: { textAlign: 'right', color: colors.text, fontSize: 30, fontWeight: '900' },
  error: { color: colors.danger, textAlign: 'right' },
  or: { color: colors.muted, textAlign: 'center', fontWeight: '700' },
});
