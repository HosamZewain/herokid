import { useQuery } from '@tanstack/react-query';
import { router } from 'expo-router';
import { Linking, StyleSheet, Text, View } from 'react-native';
import { api } from '@/src/api/client';
import type { MobileBootstrap } from '@/src/api/types';
import { useAuth } from '@/src/auth/AuthProvider';
import { Button } from '@/src/components/Button';
import { Screen } from '@/src/components/Screen';
import { useI18n } from '@/src/i18n';
import { colors } from '@/src/theme';

export default function ProfileScreen() {
  const { user, logout } = useAuth();
  const { t, toggleLocale, locale, l } = useI18n();
  const bootstrap = useQuery({ queryKey: ['bootstrap'], queryFn: () => api<MobileBootstrap>('/bootstrap') });
  const whatsapp = bootstrap.data?.data.support.whatsapp?.replace(/\D/g, '');

  return <Screen style={styles.center}>
    <View style={styles.avatar}><Text style={styles.icon}>👤</Text></View>
    {user ? <><Text style={styles.name}>{user.name}</Text><Text style={styles.meta}>{user.email ?? user.phone}</Text><Button title={l('تعديل الحساب','Edit account')} variant="ghost" onPress={() => router.push('/profile/edit')} /><Button title={l('المفضلة','Favorites')} variant="ghost" onPress={() => router.push('/favorites')} /><Button title={l('الإشعارات','Notifications')} variant="ghost" onPress={() => router.push('/notifications')} /><Button title={t.addresses} variant="ghost" onPress={() => router.push('/addresses')} /><Button title={l('الخصوصية والأمان','Privacy and security')} variant="ghost" onPress={() => router.push('/privacy')} /><Button title={t.logout} variant="ghost" onPress={() => logout()} /></> : <><Text style={styles.name}>{l('حساب HeroKid','HeroKid account')}</Text><Button title={t.signIn} onPress={() => router.push('/auth/login')} /><Button title={t.register} variant="ghost" onPress={() => router.push('/auth/register')} /></>}
    {whatsapp ? <Button title={locale === 'ar' ? 'تواصل عبر WhatsApp' : 'Contact us on WhatsApp'} variant="secondary" onPress={() => Linking.openURL(`https://wa.me/${whatsapp}`)} /> : null}
    <Button title={t.language} variant="ghost" onPress={toggleLocale} />
  </Screen>;
}

const styles=StyleSheet.create({center:{flexGrow:1,justifyContent:'center'},avatar:{width:100,height:100,borderRadius:50,alignSelf:'center',backgroundColor:'#EEF2FF',alignItems:'center',justifyContent:'center'},icon:{fontSize:48},name:{textAlign:'center',color:colors.text,fontSize:26,fontWeight:'900'},meta:{textAlign:'center',color:colors.muted}});
