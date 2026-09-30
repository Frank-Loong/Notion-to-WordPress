import { EventBusImpl } from './EventBus';

describe('EventBusImpl', () => {
  it('calls listeners in priority order and removes once listeners', () => {
    const bus = new EventBusImpl();
    const calls: string[] = [];

    bus.on('sync', () => calls.push('default'));
    bus.once('sync', () => calls.push('first'), 1);

    bus.emit('sync');
    bus.emit('sync');

    expect(calls).toEqual(['first', 'default', 'default']);
  });
});
