import { getDeviceValue } from '@/src/storage/deviceStorage';
import Constants from 'expo-constants';
import { router } from 'expo-router';
import { useEffect, useState } from 'react';
import { ActivityIndicator, Linking, StyleSheet, Text, View } from 'react-native';
import { api } from '@/src/api/client';
import type { MobileBootstrap } from '@/src/api/types';
import { Button } from '@/src/components/Button';
import { useI18n } from '@/src/i18n';
import { colors } from '@/src/theme';

function compareVersions(left: string, right: string) {
  const a = left.split('.').map(Number);
  const b = right.split('.').map(Number);
  for (let index = 0; index < Math.max(a.length, b.length); index += 1) {
    if ((a[index] ?? 0) !== (b[index] ?? 0)) return (a[index] ?? 0) - (b[index] ?? 0);
  }
  return 0;
}

export default function Index() {
  const { l } = useI18n();
  const [blocked, setBlocked] = useState<'maintenance' | 'update' | null>(null);
  const [failed, setFailed] = useState(false);
  const [retry, setRetry] = useState(0);

  useEffect(() => {
    Promise.all([api<MobileBootstrap>('/bootstrap'), getDeviceValue('herokid.onboarding.complete')])
      .then(([bootstrap, onboardingDone]) => {
        if (bootstrap.data.app.maintenance_mode) {
          setBlocked('maintenance');
          return;
        }
        const currentVersion = Constants.expoConfig?.version ?? '0.0.0';
        if (bootstrap.data.app.force_update && compareVersions(currentVersion, bootstrap.data.app.minimum_supported_version) < 0) {
          setBlocked('update');
          return;
        }
        router.replace(onboardingDone ? '/(tabs)' : '/onboarding');
      })
      .catch(() => setFailed(true));
  }, [retry]);

  if (blocked) {
    return <View style={styles.root}><Text style={styles.logo}>HEROKID</Text><Text style={styles.title}>{blocked === 'maintenance' ? l('نُجري تحسينات قصيرة', 'We’re making a few improvements') : l('يلزم تحديث التطبيق', 'App update required')}</Text><Text style={styles.body}>{blocked === 'maintenance' ? l('سنعود خلال وقت قصير. بياناتك وطلباتك محفوظة بأمان.', 'We’ll be back shortly. Your data and orders are safe.') : l('حدّث HeroKid للحصول على أحدث تحسينات الأمان والطلب.', 'Update HeroKid for the latest security and ordering improvements.')}</Text>{blocked === 'update' ? <Button title={l('فتح HeroKid', 'Open HeroKid')} onPress={() => Linking.openURL('https://hero-kid.com')} /> : null}</View>;
  }
  if (failed) {
    return <View style={styles.root}><Text style={styles.title}>{l('تعذر الاتصال بـ HeroKid', 'Could not connect to HeroKid')}</Text><Text style={styles.body}>{l('تحقق من الإنترنت وحاول مرة أخرى.', 'Check your connection and try again.')}</Text><Button title={l('إعادة المحاولة', 'Try again')} onPress={() => { setFailed(false); setRetry((value) => value + 1); }} /></View>;
  }
  return <View style={styles.root}><ActivityIndicator color={colors.primary} size="large" /></View>;
}

const styles = StyleSheet.create({root:{flex:1,alignItems:'center',justifyContent:'center',gap:18,padding:28,backgroundColor:colors.background},logo:{color:colors.primary,fontWeight:'900',letterSpacing:3},title:{color:colors.text,fontSize:26,fontWeight:'900',textAlign:'center'},body:{color:colors.muted,fontSize:17,lineHeight:27,textAlign:'center'}});
