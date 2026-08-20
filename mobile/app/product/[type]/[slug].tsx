import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Image } from 'expo-image';
import { router, useLocalSearchParams } from 'expo-router';
import { ActivityIndicator, Pressable, Share, StyleSheet, Text, View } from 'react-native';
import { api } from '@/src/api/client';
import type { CatalogItem } from '@/src/api/types';
import { useAuth } from '@/src/auth/AuthProvider';
import { Button } from '@/src/components/Button';
import { Screen } from '@/src/components/Screen';
import { useI18n } from '@/src/i18n';
import { colors, radii } from '@/src/theme';

type Favorite={type:string;id:number};
export default function ProductScreen() {
  const {type,slug}=useLocalSearchParams<{type:'story'|'product';slug:string}>();
  const {t,l,isRtl,locale}=useI18n();
  const {user}=useAuth();
  const client=useQueryClient();
  const {data,isLoading}=useQuery({queryKey:['product',type,slug,locale],queryFn:()=>api<{data:CatalogItem}>(`/catalog/${type}/${slug}?locale=${locale}`)});
  const favorites=useQuery({queryKey:['favorites',isRtl],queryFn:()=>api<{data:Favorite[]}>(`/favorites?locale=${locale}`),enabled:!!user});
  const item=data?.data;
  const numericId=Number(item?.id.split(':')[1]);
  const favorite=!!favorites.data?.data.some(row=>row.type===type&&row.id===numericId);
  const toggle=useMutation({mutationFn:()=>favorite?api(`/favorites/${type}/${numericId}`,{method:'DELETE'}):api('/favorites',{method:'POST',body:JSON.stringify({type,id:numericId})}),onSuccess:()=>client.invalidateQueries({queryKey:['favorites']})});
  if(isLoading||!item)return <Screen style={styles.center}><ActivityIndicator size="large" color={colors.primary}/></Screen>;
  return <Screen>
    {item.image_url?<Image source={item.image_url} style={styles.image} contentFit="cover"/>:<View style={[styles.image,styles.placeholder]}><Text style={styles.emoji}>📚</Text></View>}
    <View style={[styles.topRow,{flexDirection:isRtl?'row-reverse':'row'}]}><Text style={styles.badge}>{item.badge_label}</Text>{user?<Pressable accessibilityLabel={favorite?l('إزالة من المفضلة','Remove from favorites'):l('إضافة إلى المفضلة','Add to favorites')} accessibilityRole="button" onPress={()=>toggle.mutate()}><Text style={styles.favorite}>{favorite?'♥':'♡'}</Text></Pressable>:null}</View>
    <Text style={[styles.title,{textAlign:isRtl?'right':'left'}]}>{item.title}</Text><Text style={[styles.price,{textAlign:isRtl?'right':'left'}]}>{item.price.formatted}</Text><Text style={[styles.description,{textAlign:isRtl?'right':'left'}]}>{item.description||item.short_description}</Text>
    <View style={styles.facts}><Text style={[styles.fact,{textAlign:isRtl?'right':'left'}]}>{l('العمر المناسب','Suitable age')}: {item.age_range||l('حسب المنتج','See product')}</Text>{item.details?.language?<Text style={[styles.fact,{textAlign:isRtl?'right':'left'}]}>{l('اللغة','Language')}: {item.details.language==='ar'?l('العربية','Arabic'):'English'}</Text>:null}{item.details?.lesson?<Text style={[styles.fact,{textAlign:isRtl?'right':'left'}]}>{l('القيمة التعليمية','Educational value')}: {item.details.lesson}</Text>:null}{item.details?.production_lead_time_days?<Text style={[styles.fact,{textAlign:isRtl?'right':'left'}]}>{l('التجهيز المتوقع','Expected production')}: {item.details.production_lead_time_days} {l('أيام','days')}</Text>:null}{item.details?.features?.map(feature=><Text key={feature} style={[styles.fact,{textAlign:isRtl?'right':'left'}]}>• {feature}</Text>)}</View>
    <Button title={l('مشاركة المنتج','Share product')} variant="ghost" onPress={()=>Share.share({title:item.title,message:`${item.title}\nhttps://hero-kid.com/${type==='story'?'stories':'shop/product'}/${slug}`})}/>
    <View style={styles.personalization}><Text style={[styles.personalizationTitle,{textAlign:isRtl?'right':'left'}]}>{l('صُمم خصيصاً لطفلك','Made especially for your child')}</Text><Text style={[styles.personalizationText,{textAlign:isRtl?'right':'left'}]}>{item.personalization.label}</Text></View>
    <Button title={t.startPersonalization} onPress={()=>router.push({pathname:'/personalize/[type]/[slug]',params:{type,slug}})}/>
  </Screen>;
}
const styles=StyleSheet.create({center:{flexGrow:1,justifyContent:'center'},image:{width:'100%',height:340,borderRadius:radii.lg},placeholder:{alignItems:'center',justifyContent:'center',backgroundColor:'#EEF2FF'},emoji:{fontSize:90},topRow:{flexDirection:'row-reverse',justifyContent:'space-between',alignItems:'center'},badge:{color:colors.primaryDark,backgroundColor:'#EEF2FF',paddingHorizontal:10,paddingVertical:6,borderRadius:radii.pill,overflow:'hidden',fontWeight:'800'},favorite:{color:colors.accent,fontSize:36,fontWeight:'900'},title:{color:colors.text,textAlign:'right',fontSize:29,lineHeight:39,fontWeight:'900'},price:{color:colors.primary,textAlign:'right',fontSize:24,fontWeight:'900'},description:{color:colors.muted,textAlign:'right',fontSize:17,lineHeight:29},facts:{backgroundColor:'#fff',borderRadius:radii.md,padding:16,gap:8},fact:{textAlign:'right',color:colors.text,lineHeight:23},personalization:{backgroundColor:'#FFFBEB',borderRadius:radii.md,padding:16,gap:5},personalizationTitle:{textAlign:'right',color:'#92400E',fontWeight:'900'},personalizationText:{textAlign:'right',color:'#A16207'}});
