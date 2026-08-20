import { router } from 'expo-router';
import { useState } from 'react';
import { StyleSheet, Text, View } from 'react-native';
import { track } from '@/src/analytics';
import { Button } from '@/src/components/Button';
import { Screen } from '@/src/components/Screen';
import { useI18n } from '@/src/i18n';
import { setDeviceValue } from '@/src/storage/deviceStorage';
import { colors, radii } from '@/src/theme';

export default function Onboarding() {
  const { l, isRtl } = useI18n();
  const [index, setIndex] = useState(0);
  const slides = [
    { icon: '🦸', title: l('طفلك يصبح بطل الحكاية', 'Your child becomes the hero'), body: l('نحوّل اسم طفلك وصوره إلى تجربة شخصية آمنة ومبهجة.', 'We turn your child’s name and photos into a safe, joyful personalized experience.') },
    { icon: '📚', title: l('قصص وأنشطة صنعت له', 'Stories and activities made for them'), body: l('اختر ما يناسب عمره واهتماماته ثم خصّصه في خطوات بسيطة.', 'Choose what suits their age and interests, then personalize it in a few simple steps.') },
    { icon: '🎁', title: l('نراجع، نطبع، ونوصّل', 'We review, print, and deliver'), body: l('راجع التصميم ووافق عليه، ثم تابع كل مرحلة حتى باب البيت.', 'Review and approve the design, then follow every stage until it reaches your door.') },
  ];
  const finish = async () => {
    await setDeviceValue('herokid.onboarding.complete', '1');
    void track('onboarding_completed');
    router.replace('/(tabs)');
  };
  const slide = slides[index];

  return <Screen style={styles.screen}>
    <Button title={l('تخطي', 'Skip')} variant="ghost" onPress={finish} style={{ ...styles.skip, alignSelf: isRtl ? 'flex-start' : 'flex-end' }} />
    <View style={styles.visual}><Text style={styles.icon}>{slide.icon}</Text></View>
    <View style={styles.copy}>
      <Text style={[styles.title, { textAlign: isRtl ? 'right' : 'left' }]}>{slide.title}</Text>
      <Text style={[styles.body, { textAlign: isRtl ? 'right' : 'left' }]}>{slide.body}</Text>
    </View>
    <View style={styles.dots} accessibilityLabel={l(`الشريحة ${index + 1} من 3`, `Slide ${index + 1} of 3`)}>{slides.map((_, dot) => <View key={dot} style={[styles.dot, dot === index && styles.activeDot]} />)}</View>
    <Button title={index === 2 ? l('ابدأ مع HeroKid', 'Start with HeroKid') : l('التالي', 'Next')} onPress={() => index === 2 ? finish() : setIndex(index + 1)} />
  </Screen>;
}

const styles = StyleSheet.create({
  screen: { flexGrow: 1, justifyContent: 'space-between', paddingVertical: 28 },
  skip: { minHeight: 40 }, visual: { height: 290, borderRadius: radii.lg, backgroundColor: '#EEF2FF', alignItems: 'center', justifyContent: 'center' },
  icon: { fontSize: 110 }, copy: { gap: 12 }, title: { color: colors.text, fontSize: 30, fontWeight: '900' },
  body: { color: colors.muted, fontSize: 18, lineHeight: 30 }, dots: { flexDirection: 'row', justifyContent: 'center', gap: 8 },
  dot: { width: 8, height: 8, borderRadius: 4, backgroundColor: '#CBD5E1' }, activeDot: { width: 28, backgroundColor: colors.primary },
});
