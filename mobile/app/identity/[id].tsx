import { useMutation, useQuery } from '@tanstack/react-query';
import * as FileSystem from 'expo-file-system/legacy';
import * as MediaLibrary from 'expo-media-library';
import { router, useLocalSearchParams } from 'expo-router';
import * as Sharing from 'expo-sharing';
import { useEffect, useRef, useState } from 'react';
import { Alert, Linking, Share, StyleSheet, Text, View } from 'react-native';
import { track } from '@/src/analytics';
import { api } from '@/src/api/client';
import type { ChildIdentity } from '@/src/api/types';
import { AuthenticatedImage } from '@/src/components/AuthenticatedImage';
import { Button } from '@/src/components/Button';
import { Screen } from '@/src/components/Screen';
import { useI18n } from '@/src/i18n';
import { colors, radii } from '@/src/theme';

type SharePayload = {data:{status:string;message?:string;share_id?:number;publicUrl?:string;caption?:string;whatsapp?:string;facebook?:string;cards?:{feed:string;story:string;og:string}}};
type ReadyShare = Required<Pick<SharePayload['data'],'share_id'|'publicUrl'|'caption'|'whatsapp'|'facebook'|'cards'>>;

export default function IdentityResultScreen() {
  const { l, isRtl } = useI18n();
  const { id } = useLocalSearchParams<{ id: string }>();
  const [sharePayload, setSharePayload] = useState<SharePayload['data'] | null>(null);
  const [shareBusy, setShareBusy] = useState(false);
  const query = useQuery({ queryKey: ['identity', id], queryFn: () => api<{ data: ChildIdentity }>(`/child-identities/${id}`), refetchInterval: (result) => { const status = result.state.data?.data.status; return ['queued', 'processing', 'photos_uploaded'].includes(status ?? '') ? 3000 : false; } });
  const retry = useMutation({ mutationFn: () => api(`/child-identities/${id}/generate`, { method: 'POST', body: JSON.stringify({ idempotency_key: uuid() }) }), onSuccess: () => query.refetch() });
  const approve = useMutation({ mutationFn: (attemptId:number) => api(`/child-identities/${id}/attempts/${attemptId}/approve`, { method: 'POST' }), onSuccess: () => query.refetch() });
  const remove = useMutation({ mutationFn: () => api(`/child-identities/${id}`, { method: 'DELETE' }), onSuccess: () => router.replace('/identity') });
  const identity = query.data?.data;
  const attempt = identity?.attempts[0];
  const tracked = useRef(false);

  useEffect(() => { if (attempt?.status === 'succeeded' && !tracked.current) { tracked.current = true; void track('identity_generation_completed', { child_profile_id: identity?.child_profile_id, child_identity_id: identity?.id }); } }, [attempt?.status, identity?.child_profile_id, identity?.id]);

  const createShare = async () => {
    setShareBusy(true);
    try {
      const response = await api<SharePayload>(`/child-identities/${id}/share`, { method: 'POST', body: JSON.stringify({ share_consent: true }) });
      setSharePayload(response.data);
      if (response.data.status !== 'ready') Alert.alert(l('بطاقة المشاركة', 'Share card'), response.data.message ?? l('جارٍ تجهيز البطاقة. حاول مرة أخرى بعد قليل.', 'Your card is being prepared. Try again shortly.'));
    } catch (reason) {
      Alert.alert(l('تعذر تجهيز المشاركة', 'Could not prepare sharing'), reason instanceof Error ? reason.message : l('حاول مرة أخرى.', 'Please try again.'));
    } finally { setShareBusy(false); }
  };

  const consentToShare = () => Alert.alert(l('موافقة على المشاركة العامة', 'Public sharing consent'), l('سيُنشئ HeroKid بطاقة تحمل العلامة التجارية ورابطًا عامًا بدون نشر اسم طفلك. يمكنك إيقاف الرابط لاحقًا من الدعم.', 'HeroKid will create a branded card and public link without publishing your child’s name. You can disable the link later through support.'), [
    { text: l('رجوع', 'Back'), style: 'cancel' }, { text: l('أوافق وأجهز البطاقة', 'I agree — prepare the card'), onPress: createShare },
  ]);

  const recordShare = async (payload:ReadyShare, event_type:string, channel:string) => {
    void track('identity_shared', { child_profile_id: identity?.child_profile_id, child_identity_id: identity?.id, channel });
    try { await api(`/child-identities/${id}/share/${payload.share_id}/event`, { method:'POST', body:JSON.stringify({ event_type, channel }) }); } catch { /* Sharing must not fail because analytics delivery failed. */ }
  };

  const openChannel = async (channel:'whatsapp'|'facebook') => {
    if (!isReady(sharePayload)) return;
    await recordShare(sharePayload, `share.${channel}_clicked`, channel);
    await Linking.openURL(sharePayload[channel]);
  };

  const nativeShare = async () => {
    if (!isReady(sharePayload)) return;
    await recordShare(sharePayload, 'share.native_opened', 'native');
    await Share.share({ title:'HeroKid', message:sharePayload.caption, url:sharePayload.publicUrl });
  };

  const downloadCard = async (save:boolean) => {
    if (!isReady(sharePayload)) return;
    setShareBusy(true);
    try {
      const destination = `${FileSystem.cacheDirectory}herokid-identity-${id}.jpg`;
      const result = await FileSystem.downloadAsync(sharePayload.cards.feed, destination);
      if (result.status !== 200) throw new Error('Card download failed');
      if (save) {
        const permission = await MediaLibrary.requestPermissionsAsync();
        if (!permission.granted) return;
        await MediaLibrary.createAssetAsync(result.uri);
        await recordShare(sharePayload, 'share.image_saved', 'download_feed');
        Alert.alert(l('تم الحفظ', 'Saved'), l('حُفظت بطاقة HeroKid في صور جهازك.', 'The HeroKid card was saved to your photos.'));
      } else if (await Sharing.isAvailableAsync()) {
        await recordShare(sharePayload, 'share.instagram_clicked', 'instagram_feed');
        await Sharing.shareAsync(result.uri, { mimeType:'image/jpeg', dialogTitle:l('شارك بطاقة HeroKid', 'Share your HeroKid card') });
      }
    } catch { Alert.alert(l('تعذر إكمال المشاركة', 'Could not complete sharing'), l('حاول مرة أخرى.', 'Please try again.')); }
    finally { setShareBusy(false); }
  };

  return <Screen style={styles.center}>
    <Text style={[styles.title, { textAlign: isRtl ? 'right' : 'left' }]}>{identity ? l(`هوية ${identity.child_name}`, `${identity.child_name}'s identity`) : l('نجهز هوية طفلك', 'Preparing your child’s identity')}</Text>
    {attempt?.status === 'succeeded' && attempt.media_url ? <AuthenticatedImage accessibilityLabel={l('هوية الطفل الناتجة', 'Generated child identity')} uri={attempt.media_url} style={styles.result} contentFit="contain" /> : <View style={styles.processing}><Text style={styles.spark}>✨</Text><Text style={styles.processingTitle}>{attempt?.status === 'failed' ? l('تعذر إنشاء الهوية', 'Identity generation failed') : l('جارٍ إنشاء الهوية بأمان', 'Creating the identity securely')}</Text><Text style={styles.body}>{attempt?.message ?? l('يمكنك إغلاق التطبيق والعودة لاحقاً. سنحفظ التقدم في حسابك.', 'You can close the app and return later. Your progress will be saved.')}</Text></View>}
    {attempt?.status === 'failed' ? <Button title={l('إعادة المحاولة', 'Try again')} disabled={retry.isPending} onPress={() => retry.mutate()} /> : null}
    {attempt?.status === 'succeeded' && identity?.approved_attempt_id !== attempt.id ? <Button title={l('حفظ هذه الهوية في ملف طفلي', 'Save this identity to my child’s profile')} disabled={approve.isPending} onPress={() => approve.mutate(attempt.id)} /> : null}
    {attempt?.status === 'succeeded' && identity?.approved_attempt_id === attempt.id ? <>
      <Button title={l('استخدم الهوية في قصة', 'Use this identity in a story')} onPress={() => router.replace('/(tabs)/catalog')} />
      {!isReady(sharePayload) ? <Button title={shareBusy ? l('جارٍ تجهيز بطاقة المشاركة…', 'Preparing share card…') : l('مشاركة هوية طفلي', 'Share my child’s identity')} variant="secondary" disabled={shareBusy} onPress={consentToShare} /> : <View style={styles.shareBox}><Text style={[styles.shareTitle, { textAlign: isRtl ? 'right' : 'left' }]}>{l('شارك بطاقة HeroKid', 'Share your HeroKid card')}</Text><Button title="WhatsApp" variant="secondary" disabled={shareBusy} onPress={() => openChannel('whatsapp')} /><Button title="Facebook" variant="ghost" disabled={shareBusy} onPress={() => openChannel('facebook')} /><Button title={l('Instagram أو تطبيق آخر', 'Instagram or another app')} variant="ghost" disabled={shareBusy} onPress={() => downloadCard(false)} /><Button title={l('المشاركة من الهاتف', 'Use device sharing')} variant="ghost" disabled={shareBusy} onPress={nativeShare} /><Button title={l('حفظ على الجهاز', 'Save to device')} variant="ghost" disabled={shareBusy} onPress={() => downloadCard(true)} /></View>}
    </> : null}
    <Button title={l('حذف الهوية', 'Delete identity')} variant="ghost" disabled={remove.isPending} onPress={() => remove.mutate()} />
  </Screen>;
}

function isReady(payload:SharePayload['data']|null): payload is SharePayload['data'] & ReadyShare { return payload?.status === 'ready' && !!payload.share_id && !!payload.cards && !!payload.caption && !!payload.publicUrl && !!payload.whatsapp && !!payload.facebook; }
function uuid(){return'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g,c=>{const r=Math.random()*16|0;return(c==='x'?r:(r&3|8)).toString(16)})}
const styles=StyleSheet.create({center:{flexGrow:1,justifyContent:'center'},title:{color:colors.text,textAlign:'right',fontSize:30,fontWeight:'900',marginTop:28},result:{width:'100%',height:410,borderRadius:radii.lg,backgroundColor:'#fff'},processing:{height:310,borderRadius:radii.lg,backgroundColor:'#F5F3FF',alignItems:'center',justifyContent:'center',padding:24,gap:12},spark:{fontSize:70},processingTitle:{color:colors.text,fontSize:21,fontWeight:'900'},body:{color:colors.muted,textAlign:'center',fontSize:16,lineHeight:26},shareBox:{gap:10,padding:16,borderRadius:radii.lg,backgroundColor:'#FFF7ED'},shareTitle:{color:'#9A3412',fontSize:18,fontWeight:'900',textAlign:'right'}});
