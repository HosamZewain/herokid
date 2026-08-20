import AsyncStorage from '@react-native-async-storage/async-storage';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { router, useLocalSearchParams } from 'expo-router';
import { useEffect, useRef, useState } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from 'react-native';
import { track } from '@/src/analytics';
import { api } from '@/src/api/client';
import { idempotencyKey } from '@/src/api/idempotency';
import type { CatalogItem, ChildProfile, ChildProfilePhoto } from '@/src/api/types';
import { useAuth } from '@/src/auth/AuthProvider';
import { Button } from '@/src/components/Button';
import { Field } from '@/src/components/Field';
import { Screen } from '@/src/components/Screen';
import { useI18n } from '@/src/i18n';
import { colors, radii } from '@/src/theme';

type DraftPayload={item_type:'story'|'product';item_slug:string;child_profile_id:string;photo_ids:string[];dedication:string;instructions:string;variant_id:number|null};
type Draft={id:string;type:'personalization';payload:DraftPayload;version:number;last_activity_at:string};
type LocalDraft={payload:DraftPayload;saved_at:string};

export default function PersonalizationScreen() {
  const { type, slug } = useLocalSearchParams<{type:'story'|'product';slug:string}>();
  const { user } = useAuth();
  const { t, l, locale, isRtl } = useI18n();
  const client = useQueryClient();
  const [childId,setChildId]=useState('');
  const [selectedPhotos,setSelectedPhotos]=useState<string[]>([]);
  const [dedication,setDedication]=useState('');
  const [instructions,setInstructions]=useState('');
  const [variantId,setVariantId]=useState<number|null>(null);
  const [hydrated,setHydrated]=useState(false);
  const draftRef=useRef<{id:string;version:number}|null>(null);
  const draftKey=`herokid.personalization.${type}.${slug}`;

  useEffect(()=>{void track('personalization_started',{item_type:type,item_slug:slug})},[type,slug]);
  useEffect(()=>{
    if(!user)return;
    Promise.all([
      AsyncStorage.getItem(draftKey),
      api<{data:Draft[]}>('/drafts'),
    ]).then(([localRaw,remote])=>{
      const local=localRaw?JSON.parse(localRaw) as LocalDraft:null;
      const server=remote.data.find(item=>item.type==='personalization'&&item.payload.item_type===type&&item.payload.item_slug===slug);
      if(server)draftRef.current={id:server.id,version:server.version};
      const selected=local&&(!server||new Date(local.saved_at)>new Date(server.last_activity_at))?local.payload:server?.payload;
      if(selected){setChildId(selected.child_profile_id??'');setSelectedPhotos(selected.photo_ids??[]);setDedication(selected.dedication??'');setInstructions(selected.instructions??'');setVariantId(selected.variant_id??null)}
    }).catch(()=>AsyncStorage.getItem(draftKey).then(raw=>{if(raw){const payload=(JSON.parse(raw)as LocalDraft).payload;setChildId(payload.child_profile_id??'');setSelectedPhotos(payload.photo_ids??[]);setDedication(payload.dedication??'');setInstructions(payload.instructions??'');setVariantId(payload.variant_id??null)}})).finally(()=>setHydrated(true));
  },[user,type,slug,draftKey]);

  const product=useQuery({queryKey:['product',type,slug],queryFn:()=>api<{data:CatalogItem}>(`/catalog/${type}/${slug}`)});
  const children=useQuery({queryKey:['children'],queryFn:()=>api<{data:ChildProfile[]}>('/children'),enabled:!!user&&type==='story'});
  useEffect(()=>{if(hydrated&&!childId&&children.data?.data[0])setChildId(children.data.data[0].id)},[hydrated,childId,children.data]);
  const photos=useQuery({queryKey:['child-photos',childId],queryFn:()=>api<{data:ChildProfilePhoto[]}>(`/children/${childId}/photos`),enabled:!!childId&&type==='story'});
  const previousChild=useRef('');
  useEffect(()=>{if(previousChild.current&&previousChild.current!==childId)setSelectedPhotos([]);previousChild.current=childId},[childId]);

  const payload:DraftPayload={item_type:type,item_slug:slug,child_profile_id:childId,photo_ids:selectedPhotos,dedication,instructions,variant_id:variantId};
  useEffect(()=>{
    if(!hydrated||!user)return;
    const timer=setTimeout(async()=>{
      await AsyncStorage.setItem(draftKey,JSON.stringify({payload,saved_at:new Date().toISOString()} satisfies LocalDraft));
      try{
        if(draftRef.current){
          const response=await api<{data:Draft}>(`/drafts/${draftRef.current.id}`,{method:'PATCH',body:JSON.stringify({payload,version:draftRef.current.version,child_profile_id:childId||null})});
          draftRef.current={id:response.data.id,version:response.data.version};
        }else{
          const response=await api<{data:Draft}>('/drafts',{method:'POST',body:JSON.stringify({draft_type:'personalization',child_profile_id:childId||null,payload})});
          draftRef.current={id:response.data.id,version:response.data.version};
        }
      }catch{/* The encrypted local draft remains available and will sync on the next edit. */}
    },900);
    return()=>clearTimeout(timer);
  },[hydrated,user,childId,selectedPhotos,dedication,instructions,variantId,type,slug,draftKey]);

  const item=product.data?.data;
  const numericId=Number(item?.id.split(':')[1]);
  const variants=item?.details?.variants??[];
  const add=useMutation({mutationFn:()=>api('/cart/items',{method:'POST',body:JSON.stringify(type==='story'?{item_type:'story',story_id:numericId,child_profile_id:childId,child_photo_ids:selectedPhotos,dedication,additional_instructions:instructions,language:locale,idempotency_key:idempotencyKey()}:{item_type:'product',product_id:numericId,variant_id:variantId,quantity:1,idempotency_key:idempotencyKey()})}),onSuccess:async()=>{void track('personalization_completed',{item_type:type,item_id:numericId});void track('product_added_to_cart',{item_type:type,item_id:numericId});await AsyncStorage.removeItem(draftKey);if(draftRef.current)try{await api(`/drafts/${draftRef.current.id}`,{method:'DELETE'})}catch{}await Promise.all([client.invalidateQueries({queryKey:['cart']}),client.invalidateQueries({queryKey:['drafts']})]);router.replace('/(tabs)/cart')}});

  if(!user)return <Screen style={styles.center}><Text style={styles.emoji}>🔐</Text><Text style={styles.centerText}>{t.signIn}</Text><Button title={t.signIn} onPress={()=>router.push('/auth/login')}/></Screen>;
  if(product.isLoading||!item||!hydrated)return <Screen style={styles.center}><ActivityIndicator size="large" color={colors.primary}/></Screen>;
  const canAdd=type==='story'?!!childId&&selectedPhotos.length>=2&&selectedPhotos.length<=3:variants.length===0||!!variantId;
  const textAlign={textAlign:isRtl?'right' as const:'left' as const};const rowDirection={flexDirection:isRtl?'row-reverse' as const:'row' as const};
  return <Screen><Text style={[styles.title,textAlign]}>{item.title}</Text><Text style={[styles.price,textAlign]}>{item.price.formatted}</Text><Text style={[styles.saved,textAlign]}>{l('يُحفظ التخصيص تلقائيًا على هذا الجهاز وفي حسابك.','Personalization is saved automatically on this device and in your account.')}</Text>
  {type==='story'?<><Text style={[styles.section,textAlign]}>{t.selectChild}</Text>{children.data?.data.map(child=><Pressable accessibilityLabel={child.name} accessibilityRole="radio" accessibilityState={{checked:childId===child.id}} key={child.id} onPress={()=>setChildId(child.id)} style={[styles.choice,rowDirection,childId===child.id&&styles.selected]}><Text style={[styles.choiceTitle,textAlign]}>{child.name}</Text><Text>{childId===child.id?'✓':'○'}</Text></Pressable>)}{children.data?.data.length===0?<Button title={t.addChild} onPress={()=>router.push('/children/new')}/>:null}<Text style={[styles.section,textAlign]}>{t.selectPhotos}</Text>{photos.data?.data.map(photo=>{const selected=selectedPhotos.includes(photo.id);return <Pressable accessibilityLabel={l('صورة الطفل','Child photo')} accessibilityRole="checkbox" accessibilityState={{checked:selected}} key={photo.id} onPress={()=>setSelectedPhotos(current=>selected?current.filter(id=>id!==photo.id):current.length<3?[...current,photo.id]:current)} style={[styles.choice,rowDirection,selected&&styles.selected]}><Text style={[styles.choiceTitle,textAlign]}>{photo.width&&photo.height?`${photo.width} × ${photo.height}`:l('صورة الطفل','Child photo')}</Text><Text>{selected?'✓':'○'}</Text></Pressable>})}{childId&&photos.data?.data.length===0?<><Text style={[styles.help,textAlign]}>{l('ارفع صور الطفل أولاً من رحلة الهوية المجانية. يمكن إعادة استخدام الصور بعد موافقتك.','Upload child photos through the free Child Identity journey first. You can reuse them after giving consent.')}</Text><Button title={t.freeIdentity} variant="ghost" onPress={()=>router.push('/identity')}/></>:null}<Field label={t.dedication} value={dedication} onChangeText={setDedication} multiline/><Field label={t.instructions} value={instructions} onChangeText={setInstructions} multiline/></>:variants.length?<><Text style={[styles.section,textAlign]}>{l('اختر المقاس أو الخيار','Choose a size or option')}</Text>{variants.map(variant=><Pressable accessibilityLabel={locale==='ar'?variant.name_ar:(variant.name_en??variant.name_ar)} accessibilityRole="radio" accessibilityState={{checked:variantId===variant.id,disabled:!variant.available}} disabled={!variant.available} key={variant.id} onPress={()=>setVariantId(variant.id)} style={[styles.choice,rowDirection,variantId===variant.id&&styles.selected,!variant.available&&styles.disabled]}><View><Text style={[styles.choiceTitle,textAlign]}>{locale==='ar'?variant.name_ar:(variant.name_en??variant.name_ar)}</Text><Text style={[styles.meta,textAlign]}>{variant.price_amount} {l('ج.م','EGP')}</Text></View><Text>{variantId===variant.id?'✓':'○'}</Text></Pressable>)}</>:<View style={styles.summary}><Text style={[styles.choiceTitle,textAlign]}>{l('المنتج جاهز للإضافة إلى السلة','The product is ready to add to cart')}</Text></View>}
  <Button title={t.addToCart} disabled={!canAdd||add.isPending} onPress={()=>add.mutate()}/>{add.error?<Text style={styles.error}>{t.error}</Text>:null}</Screen>;
}

const styles=StyleSheet.create({center:{flexGrow:1,justifyContent:'center'},emoji:{fontSize:72,textAlign:'center'},centerText:{textAlign:'center',fontSize:20,fontWeight:'800',color:colors.text},title:{textAlign:'right',color:colors.text,fontSize:27,fontWeight:'900',marginTop:28},price:{textAlign:'right',color:colors.primary,fontWeight:'900',fontSize:21},saved:{textAlign:'right',color:colors.success,fontSize:13,fontWeight:'700'},section:{textAlign:'right',color:colors.text,fontSize:19,fontWeight:'900',marginTop:5},choice:{minHeight:58,flexDirection:'row-reverse',alignItems:'center',justifyContent:'space-between',borderWidth:1,borderColor:colors.border,borderRadius:radii.md,padding:14,backgroundColor:'#fff'},selected:{borderColor:colors.primary,backgroundColor:'#EEF2FF'},disabled:{opacity:.4},choiceTitle:{textAlign:'right',fontWeight:'800',color:colors.text},meta:{textAlign:'right',color:colors.muted,marginTop:3},help:{textAlign:'right',color:colors.muted,lineHeight:23},summary:{padding:16,backgroundColor:'#F0FDF4',borderRadius:radii.md},error:{textAlign:'right',color:colors.danger}});
