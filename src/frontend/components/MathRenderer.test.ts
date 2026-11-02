import { readFileSync } from 'fs';
import { resolve } from 'path';
import { runInNewContext } from 'vm';
import { mathRenderer } from './MathRenderer';

// Exercise the exact KaTeX and mhchem shipped in the plugin, without a CDN.
const katex = jest.requireActual('../../../assets/vendor/katex/katex.min.js');
runInNewContext(
  readFileSync(
    resolve(__dirname, '../../../assets/vendor/katex/mhchem.min.js'),
    'utf8'
  ),
  { katex }
);

describe('Notion formula rendering', () => {
  const renderer = mathRenderer as any;

  beforeEach(() => {
    document.body.replaceChildren();
    (window as any).katex = katex;
    renderer.katexLoaded = true;
    jest.spyOn(console, 'error').mockImplementation(() => {});
    jest.spyOn(console, 'log').mockImplementation(() => {});
  });

  test.each([
    ['inline', String.raw`E=mc^2`],
    ['block', String.raw`\begin{aligned}a&=b+c\\&=d\end{aligned}`],
    ['block', String.raw`\begin{pmatrix}1&2\\3&4\end{pmatrix}`],
    ['block', String.raw`\ce{2H2 + O2 -> 2H2O}`],
  ])('renders %s formula %s with bundled KaTeX', (mode, expression) => {
    const element = document.createElement(mode === 'inline' ? 'span' : 'div');
    element.className = `notion-equation-${mode}`;
    element.setAttribute('data-expression', expression);
    renderer.renderMathElement(element);
    expect(element.querySelector('.katex')).not.toBeNull();
    expect(element.querySelector('.katex-error')).toBeNull();
    expect(element.getAttribute('data-rendered')).toBe('true');
    const html = element.innerHTML;
    renderer.renderMathElement(element);
    expect(element.innerHTML).toBe(html);
  });

  test('preserves invalid source safely and allows correction and retry', () => {
    const element = document.createElement('div');
    const invalid = String.raw`\unknowncommand{<img src=x onerror=alert(1)>}`;
    element.setAttribute('data-expression', invalid);
    renderer.renderMathElement(element);
    expect(element.textContent).toBe(invalid);
    expect(element.querySelector('img')).toBeNull();
    expect(element.getAttribute('data-rendered')).toBe('false');
    expect(element.classList.contains('katex-rendered')).toBe(false);
    element.setAttribute('data-expression', 'x^2');
    renderer.renderMathElement(element);
    expect(element.getAttribute('data-rendered')).toBe('true');
    expect(element.classList.contains('katex-error')).toBe(false);
  });

  test('chemistry rendering remains unchanged on repeated lifecycle events', () => {
    const element = document.createElement('span');
    element.setAttribute('data-chemistry', 'H2O');
    renderer.renderChemistryElement(element);
    expect(element.querySelector('.katex')).not.toBeNull();
    const html = element.innerHTML;
    renderer.renderChemistryElement(element);
    expect(element.innerHTML).toBe(html);
  });
});
