import { chunk, deepClone, escapeHtml, promiseAllLimit, retry } from './common';

describe('common utilities', () => {
  it('deep-clones own properties without copying inherited properties', () => {
    const source = Object.assign(Object.create({ inherited: 'unsafe' }), {
      nested: { value: 1 },
    }) as { nested: { value: number }; inherited?: string };

    const clone = deepClone(source);
    expect(clone).toEqual({ nested: { value: 1 } });
    expect(clone.nested).not.toBe(source.nested);
    expect(Object.prototype.hasOwnProperty.call(clone, 'inherited')).toBe(
      false
    );
  });

  it('escapes HTML before it is inserted into the DOM', () => {
    expect(escapeHtml('<img src=x onerror=alert(1)>')).toBe(
      '&lt;img src=x onerror=alert(1)&gt;'
    );
  });

  it('splits arrays into positive-sized chunks', () => {
    expect(chunk([1, 2, 3, 4, 5], 2)).toEqual([[1, 2], [3, 4], [5]]);
    expect(() => chunk([1], 0)).toThrow(RangeError);
  });

  it('retries a failed operation up to the configured attempt count', async () => {
    let attempts = 0;
    const result = await retry(
      async () => {
        attempts += 1;
        if (attempts < 2) throw new Error('temporary');
        return 'ok';
      },
      2,
      0
    );

    expect(result).toBe('ok');
    expect(attempts).toBe(2);
    await expect(retry(async () => 'never', 0, 0)).rejects.toThrow(RangeError);
  });

  it('rejects invalid concurrency limits', async () => {
    await expect(promiseAllLimit([async () => 1], 0)).rejects.toThrow(
      RangeError
    );
  });
});
