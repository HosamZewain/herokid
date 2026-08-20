import { useQuery } from '@tanstack/react-query';
import { router } from 'expo-router';
import { FlatList, StyleSheet, Text, View } from 'react-native';
import { api } from '@/src/api/client';
import type { MobileBootstrap, PaginatedCatalog } from '@/src/api/types';
import { useAuth } from '@/src/auth/AuthProvider';
import { Button } from '@/src/components/Button';
import { ProductCard } from '@/src/components/ProductCard';
import { Screen } from '@/src/components/Screen';
import { useI18n } from '@/src/i18n';
import { colors, radii } from '@/src/theme';

export default function HomeScreen() {
  const { t, isRtl, locale } = useI18n();
  const { user } = useAuth();
  const catalog = useQuery({ queryKey: ['catalog', 'featured', locale], queryFn: () => api<PaginatedCatalog>(`/catalog?per_page=12&locale=${locale}`) });
  const bootstrap = useQuery({ queryKey: ['bootstrap'], queryFn: () => api<MobileBootstrap>('/bootstrap') });
  const drafts = useQuery({ queryKey: ['drafts'], queryFn: () => api<{data:{id:string;type:string;payload:{item_type?:'story'|'product';item_slug?:string}}[]}>('/drafts'), enabled:!!user });
  const banner = bootstrap.data?.data.home.banner;
  const title = locale === 'ar' ? banner?.title_ar : banner?.title_en;
  const subtitle = locale === 'ar' ? banner?.subtitle_ar : banner?.subtitle_en;
  const openBanner = () => router.push((banner?.deep_link || '/(tabs)/catalog') as never);
  const resumable = drafts.data?.data.find((draft) => draft.type === 'personalization' && draft.payload.item_type && draft.payload.item_slug);

  return <Screen>
    <View style={[styles.brandRow, { flexDirection: isRtl ? 'row-reverse' : 'row' }]}><View><Text style={[styles.eyebrow, { textAlign: isRtl ? 'right' : 'left' }]}>HEROKID</Text><Text style={styles.greeting}>{locale === 'ar' ? 'أهلاً بك 👋' : 'Welcome 👋'}</Text></View><View style={styles.avatar}><Text>🦸</Text></View></View>
    <View style={styles.hero}><Text style={styles.heroStar}>✨</Text><Text style={styles.heroTitle}>{title || t.heroTitle}</Text><Text style={styles.heroBody}>{subtitle || t.heroBody}</Text><Button title={t.explore} variant="secondary" onPress={openBanner} /></View>
    {resumable ? <Button title={locale === 'ar' ? 'متابعة التخصيص المحفوظ' : 'Continue saved personalization'} variant="ghost" onPress={() => router.push(`/personalize/${resumable.payload.item_type}/${resumable.payload.item_slug}`)} /> : null}
    <Button title={t.freeIdentity} onPress={() => router.push('/identity')} />
    <View style={styles.headingRow}><Text style={styles.sectionTitle}>{t.recommended}</Text><Text onPress={() => router.push('/(tabs)/catalog')} style={styles.link}>{locale === 'ar' ? 'عرض الكل' : 'View all'}</Text></View>
    <FlatList horizontal showsHorizontalScrollIndicator={false} data={catalog.data?.data ?? []} keyExtractor={(item) => item.id} renderItem={({ item }) => <ProductCard item={item} />} contentContainerStyle={styles.list} />
  </Screen>;
}

const styles=StyleSheet.create({brandRow:{justifyContent:'space-between',alignItems:'center'},eyebrow:{color:colors.primary,fontWeight:'900',letterSpacing:2},greeting:{color:colors.text,fontSize:22,fontWeight:'800'},avatar:{width:48,height:48,borderRadius:24,backgroundColor:'#FEF3C7',alignItems:'center',justifyContent:'center'},hero:{backgroundColor:colors.primary,borderRadius:radii.lg,padding:24,gap:13,overflow:'hidden'},heroStar:{fontSize:42,textAlign:'right'},heroTitle:{color:'#fff',textAlign:'right',fontSize:30,fontWeight:'900'},heroBody:{color:'#E0E7FF',textAlign:'right',fontSize:17,lineHeight:27},headingRow:{flexDirection:'row-reverse',justifyContent:'space-between',alignItems:'center'},sectionTitle:{color:colors.text,fontSize:21,fontWeight:'900'},link:{color:colors.primary,fontWeight:'800'},list:{gap:14,paddingBottom:4}});
