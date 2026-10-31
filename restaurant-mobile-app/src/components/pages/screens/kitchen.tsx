import { Ionicons } from '@expo/vector-icons';
import { Pressable, Text, View } from 'react-native';

import { Button, IconButton } from '@/components/ui/button';
import { Badge, EmptyState } from '@/components/ui/misc';
import { openPage, useAction } from '@/hooks/use-page';
import { errorMessage } from '@/lib/api/client';
import { minutesSince, since, time } from '@/lib/format';
import { printKitchenTicket } from '@/lib/receipt';
import { colors } from '@/lib/theme';
import { useAuth } from '@/store/auth';
import { toast } from '@/store/toast';

import { Scroll } from '../kit';
import type { ScreenProps } from '../types';

interface Ticket {
  id: string;
  number: string;
  status: 'preparing' | 'ready';
  sentAt: string;
  readyAt: string | null;
  sentBy: string;
  order: { id: string; number: string; service: 'dine_in' | 'takeaway'; table: string | null; customerName: string | null };
  lines: { id: string; name: string; quantity: number; note: string | null; voided: boolean }[];
}

/**
 * The kitchen screen: tickets oldest first, cooking then ready. The kitchen
 * marks a ticket ready; the waiter marks it served when it is out. Online it
 * is loaded again every few seconds.
 */
export function KitchenScreen({ page, path, reload }: ScreenProps<{ tickets: Ticket[] }>) {
  const { tickets } = page.props;
  const { run, busy } = useAction(path, reload);
  const canOpenOrders = (page.props.branchPermissions ?? []).includes('restaurant.orders.take') || page.props.access?.isOwner;
  const online = useAuth((s) => s.mode === 'online');
  const cooking = tickets.filter((t) => t.status === 'preparing').length;
  const ready = tickets.length - cooking;

  return (
    <Scroll onRefresh={reload}>
      <View className="flex-row items-center justify-between">
        <Text className="text-base font-semibold text-slate-700">
          {cooking} cooking · {ready} ready to take out
        </Text>
        {online ? <Text className="text-xs text-slate-400">Updates every 10 s</Text> : null}
      </View>

      {tickets.length === 0 ? (
        <EmptyState icon="flame-outline" title="Nothing to cook" message="Tickets sent from the floor show up here." />
      ) : (
        tickets.map((ticket) => {
          const isReady = ticket.status === 'ready';
          const minutes = minutesSince(ticket.sentAt);
          const late = !isReady && minutes >= 20;
          return (
            <View key={ticket.id} className={`border-2 bg-white ${isReady ? 'border-emerald-400' : late ? 'border-amber-400' : 'border-slate-200'}`}>
              <Pressable
                accessibilityRole={canOpenOrders ? 'button' : undefined}
                disabled={!canOpenOrders}
                onPress={() => openPage(`orders/${ticket.order.id}`)}
                className={`flex-row items-start gap-2 border-b px-4 py-3 ${isReady ? 'border-emerald-200 bg-emerald-50' : 'border-slate-100'}`}>
                <View className="flex-1">
                  <View className="flex-row items-center gap-1.5">
                    {ticket.order.table ? null : <Ionicons name="bag-handle-outline" size={18} color={colors.ink} />}
                    <Text className="text-xl font-bold text-slate-900">{ticket.order.table ? `Table ${ticket.order.table}` : (ticket.order.customerName ?? 'Takeaway')}</Text>
                  </View>
                  <Text className="text-sm text-slate-500">
                    {ticket.number} · {time(ticket.sentAt)} · {ticket.sentBy}
                  </Text>
                </View>
                {isReady ? <Badge label="Ready" tone="green" /> : <Badge label={since(ticket.sentAt)} tone={late ? 'amber' : 'neutral'} />}
                <IconButton
                  icon="print-outline"
                  label={`Print ticket ${ticket.number}`}
                  onPress={() =>
                    printKitchenTicket({
                      number: ticket.number,
                      where: ticket.order.table ? `Table ${ticket.order.table}` : `Takeaway${ticket.order.customerName ? ` · ${ticket.order.customerName}` : ''}`,
                      orderNumber: ticket.order.number,
                      sentAt: ticket.sentAt,
                      sentBy: ticket.sentBy,
                      lines: ticket.lines,
                    }).catch((e) => toast(errorMessage(e), 'error'))
                  }
                />
              </Pressable>
              <View className="gap-2 px-4 py-3">
                {ticket.lines.map((line) => (
                  <View key={line.id}>
                    <Text className={`text-lg ${line.voided ? 'text-slate-400 line-through' : 'text-slate-900'}`}>
                      <Text className="font-bold">{line.quantity}×</Text> {line.name}
                    </Text>
                    {line.note ? <Text className="pl-7 text-base font-semibold text-amber-800">“{line.note}”</Text> : null}
                  </View>
                ))}
              </View>
              <View className="border-t border-slate-100 p-2">
                {isReady ? (
                  <Button title="Served" icon="checkmark" variant="secondary" size="lg" disabled={busy} onPress={() => void run('POST', `kitchen/${ticket.id}/served`)} />
                ) : (
                  <Button title="Ready" icon="notifications-outline" variant="accent" size="lg" disabled={busy} onPress={() => void run('POST', `kitchen/${ticket.id}/ready`)} />
                )}
              </View>
            </View>
          );
        })
      )}
    </Scroll>
  );
}
